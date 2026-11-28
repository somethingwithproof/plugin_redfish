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
 * Rack and PDU power oversubscription and headroom analyzer.
 *
 * Grounded in Fan, Weber, and Barroso, "Power Provisioning for a
 * Warehouse-sized Computer" (ISCA 2007), where aggregate empirical peak power
 * stays below the sum of individual server nameplate ratings because workload
 * peaks are not fully coincident across a rack or PDU domain.
 */
final class RackPowerHeadroomAnalyzer {
	/**
	 * Default reserve fraction kept below PDU capacity for transient spikes
	 * and power-capping reaction latency.
	 */
	public const DEFAULT_SAFETY_MARGIN = 0.10;

	/**
	 * Lower bound on the empirical peak-to-nameplate activity factor used when
	 * sizing safe oversubscription capacity, preventing idle racks from
	 * recommending unbounded oversubscription.
	 */
	public const MIN_ACTIVITY_FACTOR = 0.25;

	/**
	 * Empirical PDU utilization fraction above which rack headroom is flagged
	 * as caution.
	 */
	public const CAUTION_UTILIZATION_RATIO = 0.80;

	/**
	 * Empirical PDU utilization fraction above which rack headroom is flagged
	 * as critical.
	 */
	public const CRITICAL_UTILIZATION_RATIO = 0.90;

	/**
	 * Analyze rack or PDU power headroom from per-node empirical and nameplate
	 * power figures.
	 *
	 * @param float                                                                                                  $pduCapacityWatts    rated PDU or rack breaker capacity in watts (must be > 0)
	 * @param list<array{id?: string, observed_watts: float, nameplate_watts: float, peak_watts?: float}>            $nodes               per-node power measurements in watts
	 * @param float                                                                                                  $safetyMargin        reserved PDU headroom fraction in [0.0, 1.0)
	 * @param float                                                                                                  $minActivityFactor   floor on empirical peak-to-nameplate ratio in (0.0, 1.0]
	 * @return array{
	 *     node_count: int,
	 *     pdu_capacity_watts: float,
	 *     usable_pdu_watts: float,
	 *     total_nameplate_watts: float,
	 *     aggregate_observed_watts: float,
	 *     aggregate_peak_watts: float,
	 *     oversubscription_ratio: float,
	 *     empirical_peak_to_nameplate_ratio: float,
	 *     effective_activity_factor: float,
	 *     peak_to_nameplate_headroom_watts: float,
	 *     peak_to_nameplate_headroom_pct: float,
	 *     pdu_headroom_watts: float,
	 *     pdu_utilization_pct: float,
	 *     safe_oversubscription_capacity_watts: float,
	 *     safe_oversubscription_ratio: float,
	 *     additional_safe_nameplate_watts: float,
	 *     risk_level: string
	 * }
	 * @throws \InvalidArgumentException when capacities, margins, or node measurements are invalid
	 */
	public static function analyzeNodes(
		float $pduCapacityWatts,
		array $nodes,
		float $safetyMargin = self::DEFAULT_SAFETY_MARGIN,
		float $minActivityFactor = self::MIN_ACTIVITY_FACTOR,
	): array {
		if (!is_finite($pduCapacityWatts) || $pduCapacityWatts <= 0.0) {
			throw new \InvalidArgumentException('PDU capacity must be a positive finite number of watts.');
		}

		if (!is_finite($safetyMargin) || $safetyMargin < 0.0 || $safetyMargin >= 1.0) {
			throw new \InvalidArgumentException('Safety margin must be within [0.0, 1.0).');
		}

		if (!is_finite($minActivityFactor) || $minActivityFactor <= 0.0 || $minActivityFactor > 1.0) {
			throw new \InvalidArgumentException('Minimum activity factor must be within (0.0, 1.0].');
		}

		$totalNameplate = 0.0;
		$totalObserved  = 0.0;
		$totalPeak      = 0.0;

		foreach ($nodes as $index => $node) {
			$observed  = $node['observed_watts'];
			$nameplate = $node['nameplate_watts'];
			$peak      = $node['peak_watts'] ?? $observed;

			if (!is_finite($observed) || $observed < 0.0) {
				throw new \InvalidArgumentException(sprintf('Node %d has an invalid observed_watts value.', $index));
			}

			if (!is_finite($nameplate) || $nameplate <= 0.0) {
				throw new \InvalidArgumentException(sprintf('Node %d must declare a positive nameplate_watts value.', $index));
			}

			if (!is_finite($peak) || $peak < $observed) {
				throw new \InvalidArgumentException(sprintf('Node %d peak_watts must be finite and at least observed_watts.', $index));
			}

			$totalNameplate += $nameplate;
			$totalObserved  += $observed;
			$totalPeak      += $peak;
		}

		$usablePdu      = $pduCapacityWatts * (1.0 - $safetyMargin);
		$oversubRatio   = $totalNameplate > 0.0 ? $totalNameplate / $pduCapacityWatts : 0.0;
		$empiricalRatio = $totalNameplate > 0.0 ? $totalPeak / $totalNameplate : 0.0;
		$effectiveAlpha = $totalNameplate > 0.0 ? max($minActivityFactor, min(1.0, $empiricalRatio)) : 1.0;

		// Safe nameplate capacity under Fan et al. statistical multiplexing:
		// Nameplate_safe = (PDU_cap * (1 - margin)) / alpha_eff.
		$safeCapacityWatts = $usablePdu / $effectiveAlpha;
		$safeOversubRatio  = $safeCapacityWatts / $pduCapacityWatts;
		$additionalSafe    = max(0.0, $safeCapacityWatts - $totalNameplate);

		$peakHeadroomWatts = $totalNameplate - $totalPeak;
		$peakHeadroomPct   = $totalNameplate > 0.0 ? ($peakHeadroomWatts / $totalNameplate) * 100.0 : 0.0;
		$pduHeadroomWatts  = $pduCapacityWatts - $totalPeak;
		$pduUtilRatio      = $totalPeak / $pduCapacityWatts;
		$pduUtilPct        = $pduUtilRatio * 100.0;

		$riskLevel = 'ok';

		if ($pduUtilRatio >= self::CRITICAL_UTILIZATION_RATIO || $totalPeak > $usablePdu) {
			$riskLevel = 'critical';
		} elseif ($pduUtilRatio >= self::CAUTION_UTILIZATION_RATIO || $totalNameplate > $safeCapacityWatts) {
			$riskLevel = 'caution';
		}

		return [
			'node_count'                           => count($nodes),
			'pdu_capacity_watts'                   => round($pduCapacityWatts, 2),
			'usable_pdu_watts'                     => round($usablePdu, 2),
			'total_nameplate_watts'                => round($totalNameplate, 2),
			'aggregate_observed_watts'             => round($totalObserved, 2),
			'aggregate_peak_watts'                 => round($totalPeak, 2),
			'oversubscription_ratio'               => round($oversubRatio, 4),
			'empirical_peak_to_nameplate_ratio'    => round($empiricalRatio, 4),
			'effective_activity_factor'            => round($effectiveAlpha, 4),
			'peak_to_nameplate_headroom_watts'     => round($peakHeadroomWatts, 2),
			'peak_to_nameplate_headroom_pct'       => round($peakHeadroomPct, 2),
			'pdu_headroom_watts'                   => round($pduHeadroomWatts, 2),
			'pdu_utilization_pct'                  => round($pduUtilPct, 2),
			'safe_oversubscription_capacity_watts' => round($safeCapacityWatts, 2),
			'safe_oversubscription_ratio'          => round($safeOversubRatio, 4),
			'additional_safe_nameplate_watts'      => round($additionalSafe, 2),
			'risk_level'                           => $riskLevel,
		];
	}

	/**
	 * Compute statistical multiplexing diversity between the coincident rack
	 * peak and the non-coincident sum of individual server peaks.
	 *
	 * Each entry in $serverSeries is a time-aligned list of power samples (W)
	 * for one server. A diversity ratio below 1.0 quantifies the power
	 * provisioning savings realized by aggregating workloads on a shared PDU.
	 *
	 * @param list<list<float>> $serverSeries time-aligned power readings per server
	 * @return array{
	 *     server_count: int,
	 *     sample_count: int,
	 *     coincident_peak_watts: float,
	 *     non_coincident_peak_sum_watts: float,
	 *     diversity_factor: float,
	 *     multiplexing_headroom_watts: float,
	 *     multiplexing_savings_pct: float
	 * }
	 * @throws \InvalidArgumentException when series lengths mismatch or readings are negative
	 */
	public static function coincidentPeak(array $serverSeries): array {
		if ($serverSeries === []) {
			return [
				'server_count'                  => 0,
				'sample_count'                  => 0,
				'coincident_peak_watts'         => 0.0,
				'non_coincident_peak_sum_watts' => 0.0,
				'diversity_factor'              => 1.0,
				'multiplexing_headroom_watts'   => 0.0,
				'multiplexing_savings_pct'      => 0.0,
			];
		}

		$sampleCount = count($serverSeries[0]);

		if ($sampleCount === 0) {
			throw new \InvalidArgumentException('Server power series must contain at least one sample.');
		}

		$nonCoincidentSum = 0.0;
		$totalsByStep     = array_fill(0, $sampleCount, 0.0);

		foreach ($serverSeries as $serverIndex => $samples) {
			if (count($samples) !== $sampleCount) {
				throw new \InvalidArgumentException(sprintf('Server series %d length (%d) does not match expected %d samples.', $serverIndex, count($samples), $sampleCount));
			}

			$serverMax = 0.0;

			foreach ($samples as $step => $watts) {
				if (!is_finite($watts) || $watts < 0.0) {
					throw new \InvalidArgumentException(sprintf('Invalid power reading at server %d step %d.', $serverIndex, $step));
				}

				if ($watts > $serverMax) {
					$serverMax = $watts;
				}

				$totalsByStep[$step] += $watts;
			}

			$nonCoincidentSum += $serverMax;
		}

		$coincidentPeak = max($totalsByStep);
		$diversity      = $nonCoincidentSum > 0.0 ? $coincidentPeak / $nonCoincidentSum : 1.0;
		$headroom       = max(0.0, $nonCoincidentSum - $coincidentPeak);
		$savingsPct     = $nonCoincidentSum > 0.0 ? ($headroom / $nonCoincidentSum) * 100.0 : 0.0;

		return [
			'server_count'                  => count($serverSeries),
			'sample_count'                  => $sampleCount,
			'coincident_peak_watts'         => round($coincidentPeak, 2),
			'non_coincident_peak_sum_watts' => round($nonCoincidentSum, 2),
			'diversity_factor'              => round($diversity, 4),
			'multiplexing_headroom_watts'   => round($headroom, 2),
			'multiplexing_savings_pct'      => round($savingsPct, 2),
		];
	}

	/**
	 * Analyze a Cacti Redfish rack directly from cached BMC NodeCache snapshots.
	 *
	 * Uses each BMC's fresh node power_watts reading and, when present, its
	 * power_capacity_watts or power_cap_watts reading as nameplate power,
	 * falling back to $defaultNameplateWatts per reporting server.
	 *
	 * @param Rack                $rack                  rack definition carrying the PDU power budget
	 * @param list<?NodeCache>    $memberCaches          cached snapshots for each rack member
	 * @param FreshnessEvaluator  $freshness             freshness evaluator for cache readings
	 * @param float               $defaultNameplateWatts fallback per-server nameplate watts when cache lacks capacity
	 * @param float               $safetyMargin          reserved PDU headroom fraction in [0.0, 1.0)
	 * @return array<string, mixed>|null null when rack has no power budget or reporting nodes lack nameplate ratings
	 */
	public static function analyzeRack(
		Rack $rack,
		array $memberCaches,
		FreshnessEvaluator $freshness,
		float $defaultNameplateWatts = 0.0,
		float $safetyMargin = self::DEFAULT_SAFETY_MARGIN,
	): ?array {
		if ($rack->powerBudgetWatts === null || $rack->powerBudgetWatts <= 0.0) {
			return null;
		}

		$nodes = [];

		foreach ($memberCaches as $cache) {
			if ($cache === null) {
				continue;
			}

			$observed = $cache->served(Member::NODE, 'power_watts', $freshness);

			if ($observed === null || $observed < 0.0) {
				continue;
			}

			$nameplate = $cache->served(Member::NODE, 'power_capacity_watts', $freshness)
				?? $cache->served(Member::NODE, 'power_cap_watts', $freshness)
				?? ($defaultNameplateWatts > 0.0 ? $defaultNameplateWatts : null);

			if ($nameplate === null || $nameplate <= 0.0) {
				continue;
			}

			$nodes[] = [
				'id'              => 'bmc:' . $cache->bmcId,
				'observed_watts'  => $observed,
				'nameplate_watts' => max($nameplate, $observed),
				'peak_watts'      => $observed,
			];
		}

		if ($nodes === []) {
			return null;
		}

		$analysis = self::analyzeNodes($rack->powerBudgetWatts, $nodes, $safetyMargin);

		return [
			'rack_id'          => $rack->id,
			'rack_name'        => $rack->name,
			'members_total'    => count($memberCaches),
			'members_analyzed' => count($nodes),
		] + $analysis;
	}
}
