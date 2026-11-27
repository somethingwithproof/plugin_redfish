<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_redfish, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_redfish
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

namespace Somethingwithproof\Redfish;

/**
 * Inlet temperature distribution and intake-to-exhaust delta-T anomaly analyzer.
 *
 * Grounded in El-Sayed, Stefanovici, Amvrosiadis, Hwang, and Schroeder,
 * "Temperature Management in Data Centers: Why Some (Might) Like It Hot"
 * (ACM SIGMETRICS 2012): evaluating fleet inlet temperature distributions,
 * thermal variance, and per-chassis intake-to-exhaust delta-T identifies hot
 * aisle recirculation, airflow obstruction, and fan degradation earlier and
 * more accurately than static OEM critical thresholds alone.
 */
final class ThermalDeltaAnalyzer {
	/**
	 * ASHRAE TC 9.9 recommended minimum inlet temperature in degrees Celsius.
	 */
	public const ASHRAE_RECOMMENDED_MIN_C = 18.0;

	/**
	 * ASHRAE TC 9.9 recommended maximum inlet temperature in degrees Celsius.
	 */
	public const ASHRAE_RECOMMENDED_MAX_C = 27.0;

	/**
	 * ASHRAE Class A1 allowable maximum inlet temperature in degrees Celsius.
	 */
	public const ASHRAE_ALLOWABLE_MAX_C = 32.0;

	/**
	 * Lower bound of expected healthy intake-to-exhaust temperature rise (C).
	 * Values below this indicate recirculation, reversed airflow, or misplaced sensors.
	 */
	public const DEFAULT_MIN_DELTA_C = 4.0;

	/**
	 * Upper bound of expected healthy intake-to-exhaust temperature rise (C).
	 * Values above this indicate restricted airflow or degraded cooling.
	 */
	public const DEFAULT_MAX_DELTA_C = 25.0;

	/**
	 * Robust z-score scaling constant converting MAD to standard-deviation units
	 * for a normal distribution (1 / Phi^-1(0.75) ~= 1.4826).
	 */
	private const MAD_TO_SIGMA = 1.4826;

	/**
	 * Analyze inlet temperature distributions and per-node delta-T anomalies.
	 *
	 * @param list<array{id: string, inlet_c: float, exhaust_c?: ?float}> $samples    thermal samples per server or chassis
	 * @param float                                                        $minDeltaC  lower bound for expected delta-T in Celsius
	 * @param float                                                        $maxDeltaC  upper bound for expected delta-T in Celsius
	 * @param float                                                        $allowMaxC  allowable inlet ceiling in Celsius
	 * @return array{
	 *     sample_count: int,
	 *     delta_sample_count: int,
	 *     inlet_distribution: array{
	 *         min_c: ?float,
	 *         max_c: ?float,
	 *         mean_c: ?float,
	 *         median_c: ?float,
	 *         p95_c: ?float,
	 *         std_dev_c: ?float,
	 *         ashrae_recommended_pct: float,
	 *         above_allowable_count: int
	 *     },
	 *     delta_distribution: array{
	 *         min_c: ?float,
	 *         max_c: ?float,
	 *         mean_c: ?float,
	 *         median_c: ?float,
	 *         mad_c: ?float
	 *     },
	 *     nodes: list<array{
	 *         id: string,
	 *         inlet_c: float,
	 *         exhaust_c: ?float,
	 *         delta_c: ?float,
	 *         robust_z: ?float,
	 *         anomaly_score: float,
	 *         severity: string,
	 *         reasons: list<string>
	 *     }>,
	 *     max_anomaly_score: float,
	 *     fleet_severity: string
	 * }
	 * @throws \InvalidArgumentException when temperature bounds or sensor readings are non-finite
	 */
	public static function analyze(
		array $samples,
		float $minDeltaC = self::DEFAULT_MIN_DELTA_C,
		float $maxDeltaC = self::DEFAULT_MAX_DELTA_C,
		float $allowMaxC = self::ASHRAE_ALLOWABLE_MAX_C,
	): array {
		if (!is_finite($minDeltaC) || !is_finite($maxDeltaC) || $minDeltaC < 0.0 || $maxDeltaC <= $minDeltaC) {
			throw new \InvalidArgumentException('Delta-T bounds must be finite with 0 <= minDeltaC < maxDeltaC.');
		}

		if (!is_finite($allowMaxC) || $allowMaxC <= self::ASHRAE_RECOMMENDED_MIN_C) {
			throw new \InvalidArgumentException('Allowable inlet ceiling must exceed ASHRAE recommended minimum.');
		}

		if ($samples === []) {
			return [
				'sample_count'       => 0,
				'delta_sample_count' => 0,
				'inlet_distribution' => [
					'min_c'                  => null,
					'max_c'                  => null,
					'mean_c'                 => null,
					'median_c'               => null,
					'p95_c'                  => null,
					'std_dev_c'              => null,
					'ashrae_recommended_pct' => 0.0,
					'above_allowable_count'  => 0,
				],
				'delta_distribution' => [
					'min_c'    => null,
					'max_c'    => null,
					'mean_c'   => null,
					'median_c' => null,
					'mad_c'    => null,
				],
				'nodes'              => [],
				'max_anomaly_score'  => 0.0,
				'fleet_severity'     => 'normal',
			];
		}

		$inlets  = [];
		$deltas  = [];
		$parsed  = [];
		$inRec   = 0;
		$overMax = 0;

		foreach ($samples as $index => $sample) {
			$inlet   = $sample['inlet_c'];
			$exhaust = $sample['exhaust_c'] ?? null;

			if (!is_finite($inlet) || $inlet < -50.0 || $inlet > 150.0) {
				throw new \InvalidArgumentException(sprintf('Sample %d has an out-of-range inlet temperature.', $index));
			}

			if ($exhaust !== null && (!is_finite($exhaust) || $exhaust < -50.0 || $exhaust > 180.0)) {
				throw new \InvalidArgumentException(sprintf('Sample %d has an out-of-range exhaust temperature.', $index));
			}

			$inlets[] = $inlet;

			if ($inlet >= self::ASHRAE_RECOMMENDED_MIN_C && $inlet <= self::ASHRAE_RECOMMENDED_MAX_C) {
				$inRec++;
			}

			if ($inlet > $allowMaxC) {
				$overMax++;
			}

			$delta = $exhaust !== null ? $exhaust - $inlet : null;

			if ($delta !== null) {
				$deltas[] = $delta;
			}

			$parsed[] = [
				'id'        => $sample['id'],
				'inlet_c'   => $inlet,
				'exhaust_c' => $exhaust,
				'delta_c'   => $delta,
			];
		}

		$sortedInlets = $inlets;
		sort($sortedInlets);
		$inletMean   = array_sum($inlets) / count($inlets);
		$inletMedian = self::percentile($sortedInlets, 0.50);
		$inletP95    = self::percentile($sortedInlets, 0.95);
		$inletStd    = self::stdDev($inlets, $inletMean);

		$deltaMedian = null;
		$deltaMad    = null;
		$deltaMean   = null;
		$deltaMin    = null;
		$deltaMax    = null;

		if ($deltas !== []) {
			$sortedDeltas = $deltas;
			sort($sortedDeltas);
			$deltaMin    = $sortedDeltas[0];
			$deltaMax    = $sortedDeltas[count($sortedDeltas) - 1];
			$deltaMean   = array_sum($deltas) / count($deltas);
			$deltaMedian = self::percentile($sortedDeltas, 0.50);
			$absDevs     = array_map(static fn (float $v): float => abs($v - $deltaMedian), $deltas);
			sort($absDevs);
			$deltaMad = self::percentile($absDevs, 0.50);
		}

		$nodes    = [];
		$maxScore = 0.0;

		foreach ($parsed as $item) {
			$score   = 0.0;
			$reasons = [];
			$robustZ = null;
			$inlet   = $item['inlet_c'];
			$delta   = $item['delta_c'];

			if ($inlet > $allowMaxC) {
				$score    += min(50.0, 25.0 + ($inlet - $allowMaxC) * 4.0);
				$reasons[] = 'inlet_above_allowable';
			} elseif ($inlet > self::ASHRAE_RECOMMENDED_MAX_C) {
				$score    += min(20.0, ($inlet - self::ASHRAE_RECOMMENDED_MAX_C) * 3.0);
				$reasons[] = 'inlet_above_recommended';
			}

			if ($delta !== null) {
				if ($delta <= 0.0) {
					$score    += 60.0;
					$reasons[] = 'non_positive_delta_t';
				} elseif ($delta < $minDeltaC) {
					$score    += min(35.0, ($minDeltaC - $delta) * 8.0);
					$reasons[] = 'low_delta_t_recirculation';
				} elseif ($delta > $maxDeltaC) {
					$score    += min(55.0, 20.0 + ($delta - $maxDeltaC) * 4.0);
					$reasons[] = 'high_delta_t_airflow_restriction';
				}

				if ($deltaMedian !== null && count($deltas) >= 3) {
					$scale = max(1.0, ($deltaMad ?? 0.0) * self::MAD_TO_SIGMA);
					$robustZ = ($delta - $deltaMedian) / $scale;

					if (abs($robustZ) >= 3.0) {
						$score    += min(35.0, (abs($robustZ) - 2.0) * 10.0);
						$reasons[] = $robustZ > 0.0 ? 'peer_outlier_high_delta_t' : 'peer_outlier_low_delta_t';
					}
				}
			}

			$score = round(min(100.0, $score), 2);

			if ($score > $maxScore) {
				$maxScore = $score;
			}

			$nodes[] = [
				'id'            => $item['id'],
				'inlet_c'       => round($inlet, 2),
				'exhaust_c'     => $item['exhaust_c'] !== null ? round($item['exhaust_c'], 2) : null,
				'delta_c'       => $delta !== null ? round($delta, 2) : null,
				'robust_z'      => $robustZ !== null ? round($robustZ, 3) : null,
				'anomaly_score' => $score,
				'severity'      => self::classifyScore($score),
				'reasons'       => $reasons,
			];
		}

		return [
			'sample_count'       => count($samples),
			'delta_sample_count' => count($deltas),
			'inlet_distribution' => [
				'min_c'                  => round($sortedInlets[0], 2),
				'max_c'                  => round($sortedInlets[count($sortedInlets) - 1], 2),
				'mean_c'                 => round($inletMean, 2),
				'median_c'               => round($inletMedian, 2),
				'p95_c'                  => round($inletP95, 2),
				'std_dev_c'              => round($inletStd, 3),
				'ashrae_recommended_pct' => round(($inRec / count($samples)) * 100.0, 2),
				'above_allowable_count'  => $overMax,
			],
			'delta_distribution' => [
				'min_c'    => $deltaMin !== null ? round($deltaMin, 2) : null,
				'max_c'    => $deltaMax !== null ? round($deltaMax, 2) : null,
				'mean_c'   => $deltaMean !== null ? round($deltaMean, 2) : null,
				'median_c' => $deltaMedian !== null ? round($deltaMedian, 2) : null,
				'mad_c'    => $deltaMad !== null ? round($deltaMad, 3) : null,
			],
			'nodes'              => $nodes,
			'max_anomaly_score'  => $maxScore,
			'fleet_severity'     => self::classifyScore($maxScore),
		];
	}

	/**
	 * Extract inlet and exhaust temperature samples from cached BMC NodeCache
	 * snapshots, using fresh readings only.
	 *
	 * @param list<?NodeCache>   $caches    cached node snapshots
	 * @param FreshnessEvaluator $freshness freshness evaluator
	 * @return list<array{id: string, inlet_c: float, exhaust_c: ?float}>
	 */
	public static function extractSamples(array $caches, FreshnessEvaluator $freshness): array {
		$samples = [];

		foreach ($caches as $cache) {
			if ($cache === null) {
				continue;
			}

			$inlet   = $cache->served(Member::NODE, 'inlet_temp_c', $freshness);
			$exhaust = $cache->served(Member::NODE, 'exhaust_temp_c', $freshness);

			if ($inlet === null || $exhaust === null) {
				foreach ($cache->membersOf('temperature') as $member) {
					$value = $cache->served($member['key'], 'temp_c', $freshness);

					if ($value === null) {
						continue;
					}

					$label = strtolower($member['name'] . ' ' . $member['key']);

					if ($inlet === null && preg_match('/\b(inlet|intake|ambient)\b/', $label) === 1) {
						$inlet = $value;
					} elseif ($exhaust === null && preg_match('/\b(exhaust|outlet)\b/', $label) === 1) {
						$exhaust = $value;
					}
				}
			}

			if ($inlet !== null) {
				$samples[] = [
					'id'        => 'bmc:' . $cache->bmcId,
					'inlet_c'   => $inlet,
					'exhaust_c' => $exhaust,
				];
			}
		}

		return $samples;
	}

	/**
	 * Map a normalized 0..100 anomaly score to a discrete severity label.
	 *
	 * @param float $score normalized anomaly score
	 * @return string one of normal, elevated, anomalous, critical
	 */
	private static function classifyScore(float $score): string {
		if ($score >= 60.0) {
			return 'critical';
		}

		if ($score >= 30.0) {
			return 'anomalous';
		}

		if ($score >= 10.0) {
			return 'elevated';
		}

		return 'normal';
	}

	/**
	 * Compute a linear-interpolated percentile over a non-empty sorted list.
	 *
	 * @param non-empty-list<float> $sorted values sorted ascending
	 * @param float                 $p      quantile in [0.0, 1.0]
	 * @return float
	 */
	private static function percentile(array $sorted, float $p): float {
		$count = count($sorted);

		if ($count === 1) {
			return $sorted[0];
		}

		$rank  = $p * ($count - 1);
		$lower = (int) floor($rank);
		$upper = (int) ceil($rank);

		if ($lower === $upper) {
			return $sorted[$lower];
		}

		$weight = $rank - $lower;

		return $sorted[$lower] * (1.0 - $weight) + $sorted[$upper] * $weight;
	}

	/**
	 * Compute population standard deviation for a non-empty series.
	 *
	 * @param non-empty-list<float> $values samples
	 * @param float                 $mean   arithmetic mean
	 * @return float
	 */
	private static function stdDev(array $values, float $mean): float {
		$variance = 0.0;

		foreach ($values as $value) {
			$diff      = $value - $mean;
			$variance += $diff * $diff;
		}

		return sqrt($variance / count($values));
	}
}
