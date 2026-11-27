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
 * The graph_buttons link from any graph of a device with a BMC to all of
 * that device's Redfish graphs (REQ-GRAPH-003), so an operator looking at a
 * server's SNMP graphs finds its BMC graphs, and back. Cacti's graph and
 * device permissions still decide what the linked page shows; the link
 * itself names only the device, never the BMC's address or credential.
 */
final class GraphLinks {
	/**
	 * Initialize a new GraphLinks instance.
	 *
	 * @param BmcRepository $bmcs
	 * @param CactiObjectRepository $objects
	 */
	public function __construct(
		private readonly BmcRepository $bmcs,
		private readonly CactiObjectRepository $objects,
	) {
	}

	/**
	 * The device whose Redfish graphs the graph links to, or 0 for no link.
	 */
	public function hostFor(int $localGraphId): int {
		$hostId = $localGraphId > 0 ? $this->objects->hostOfGraph($localGraphId) : 0;

		return $hostId > 0 && $this->bmcs->findByHost($hostId) !== null ? $hostId : 0;
	}

	/**
	 * Html.
	 *
	 * @param string $urlPath
	 * @param int $hostId
	 * @return string
	 */
	public static function html(string $urlPath, int $hostId): string {
		$url   = $urlPath . 'graph_view.php?action=preview&reset=1&host_id=' . $hostId . '&rfilter=' . rawurlencode('Redfish');
		$title = __('Redfish graphs of this device', 'redfish');

		return '<a class="iconLink" role="link" href="' . html_escape($url) . '" title="' . html_escape($title) . '" aria-label="' . html_escape($title) . '"><img class="drillDown" src="' . html_escape($urlPath . 'images/server_chart_curve.png') . '" alt=""></a><br/>';
	}
}
