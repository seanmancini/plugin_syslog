<?php
/*
 +-------------------------------------------------------------------------+
 | Copyright (C) 2007-2022 The Cacti Group                                 |
 |                                                                         |
 | This program is free software; you can redistribute it and/or           |
 | modify it under the terms of the GNU General Public License             |
 | as published by the Free Software Foundation; either version 2          |
 | of the License, or (at your option) any later version.                  |
 |                                                                         |
 | This program is distributed in the hope that it will be useful,         |
 | but WITHOUT ANY WARRANTY; without even the implied warranty of          |
 | MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the           |
 | GNU General Public License for more details.                            |
 +-------------------------------------------------------------------------+
 | Cacti: The Complete RRDTool-based Graphing Solution                     |
 +-------------------------------------------------------------------------+
 | This code is designed, written, and maintained by the Cacti Group. See  |
 | about.php and/or the AUTHORS file for specific developer information.   |
 +-------------------------------------------------------------------------+
 | http://www.cacti.net/                                                   |
 +-------------------------------------------------------------------------+
*/

function syslog_allow_edits() {
	global $config;

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] > 1) {
			return false;
		}
	}

	return true;
}

function syslog_sync_save($data, $table, $primary = '') {
	global $config;

	include(SYSLOG_CONFIG);

	if (read_config_option('syslog_remote_enabled') == 'on' && read_config_option('syslog_remote_sync_rules') == 'on') {
		if ($config['poller_id'] == 1) {
			$stable = '`' . $syslogdb_default . '`.`' . $table . '`';

			$id = syslog_sql_save($data, $stable, $primary);

			if ($id > 0) {
				raise_message(1);
			} else {
				raise_message(2);
			}

			$pollers = array_rekey(
				db_fetch_assoc('SELECT poller_id
					FROM pollers
					WHERE disabled = ""
					AND id > 1'),
				'id', 'id'
			);

			if (cacti_sizeof($pollers)) {
				foreach($pollers as $poller_id) {
					$rcnn_id = poller_connect_to_remote($poller_id);

					if ($rcnn_id !== false) {
						$id = sql_save($data, $table, $primary, true, $rcnn_id);
					}
				}
			}
		} else {
			raise_message('syslog_denied', __('Save Failed.  Remote Data Collectors in Sync Mode are not allowed to Save Rules.  Save from the Main Cacti Server instead.', 'syslog'), MESSAGE_LEVEL_ERROR);
		}
	} else {
		$stable = '`' . $syslogdb_default . '`.`' . $table . '`';

		$id = syslog_sql_save($data, $stable, $primary);

		if ($id > 0) {
			raise_message(1);
		} else {
			raise_message(2);
		}
	}
}

function syslog_sendemail($to, $from, $subject, $message, $smsmessage = '') {
	syslog_debug("Sending Alert email to '" . $to . "'");

	$sms    = '';
	$nonsms = '';
	/* if there are SMS emails, process separately */
	if (substr_count($to, 'sms@')) {
		$emails = explode(',', $to);

		if (cacti_sizeof($emails)) {
			foreach($emails as $email) {
				if (substr_count($email, 'sms@')) {
					$sms .= (strlen($sms) ? ', ':'') . str_replace('sms@', '', trim($email));
				} else {
					$nonsms .= (strlen($nonsms) ? ', ':'') . trim($email);
				}
			}
		}
	} else {
		$nonsms = $to;
	}

	if (strlen($sms) && $smsmessage != '') {
		mailer($from, $sms, '', '', '', $subject, '', $smsmessage);
	}

	if (strlen($nonsms)) {
		if (read_config_option('syslog_html') == 'on') {
			mailer($from, $nonsms, '', '', '', $subject, $message, __('Please use an HTML Email Client', 'syslog'));
		} else {
			$message = strip_tags(str_replace('<br>', "\n", $message));
			mailer($from, $nonsms, '', '', '', $subject, '', $message, '', '', false);
		}
	}
}

function syslog_is_partitioned() {
	global $syslogdb_default;

	/* see if the table is partitioned */
	$syntax = syslog_db_fetch_row("SHOW CREATE TABLE `" . $syslogdb_default . "`.`syslog`");
	if (substr_count($syntax['Create Table'], 'PARTITION')) {
		return true;
	} else {
		return false;
	}
}

/**
 * This function will manage old data for non-partitioned tables
 */
function syslog_traditional_manage() {
	global $syslogdb_default, $syslog_cnn;

	/* determine the oldest date to retain */
	if (read_config_option('syslog_retention') > 0) {
		$retention = date('Y-m-d', time() - (86400 * read_config_option('syslog_retention')));
	}

	/* delete from the main syslog table first */
	syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog` WHERE logtime < '$retention'");

	$syslog_deleted = db_affected_rows($syslog_cnn);

	/* now delete from the syslog removed table */
	syslog_db_execute("DELETE FROM `" . $syslogdb_default . "`.`syslog_removed` WHERE logtime < '$retention'");

	$syslog_deleted += db_affected_rows($syslog_cnn);

	syslog_debug("Deleted " . $syslog_deleted .
		",  Syslog Message(s)" .
		" (older than $retention)");

	return $syslog_deleted;
}

/**
 * This function will manage a partitioned table by checking for time to create
 */
function syslog_partition_manage() {
	$syslog_deleted = 0;

	if (syslog_partition_check('syslog')) {
		syslog_partition_create('syslog');
		$syslog_deleted = syslog_partition_remove('syslog');
	}

	if (syslog_partition_check('syslog_removed')) {
		syslog_partition_create('syslog_removed');
		$syslog_deleted += syslog_partition_remove('syslog_removed');
	}

	return $syslog_deleted;
}

/**
 * This function will create a new partition for the specified table.
 */
function syslog_partition_create($table) {
	global $syslogdb_default;

	/* determine the format of the table name */
	$time    = time();
	$cformat = 'd' . date('Ymd', $time);
	$lnow    = date('Y-m-d', $time+86400);

	$exists = syslog_db_fetch_row("SELECT *
		FROM `information_schema`.`partitions`
        WHERE table_schema='" . $syslogdb_default . "'
		AND partition_name='" . $cformat . "'
		AND table_name='syslog'
        ORDER BY partition_ordinal_position");

	if (!cacti_sizeof($exists)) {
		cacti_log("SYSLOG: Creating new partition '$cformat'", false, 'SYSTEM');
		syslog_debug("Creating new partition '$cformat'");
		syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`$table` REORGANIZE PARTITION dMaxValue INTO (
			PARTITION $cformat VALUES LESS THAN (TO_DAYS('$lnow')),
			PARTITION dMaxValue VALUES LESS THAN MAXVALUE)");
	}
}

/**
 * This function will remove all old partitions for the specified table.
 */
function syslog_partition_remove($table) {
	global $syslogdb_default;

	$syslog_deleted = 0;
	$number_of_partitions = syslog_db_fetch_assoc("SELECT *
		FROM `information_schema`.`partitions`
		WHERE table_schema='" . $syslogdb_default . "' AND table_name='syslog'
		ORDER BY partition_ordinal_position");

	$days = read_config_option('syslog_retention');

	syslog_debug("There are currently '" . sizeof($number_of_partitions) . "' Syslog Partitions, We will keep '$days' of them.");

	if ($days > 0) {
		$user_partitions = sizeof($number_of_partitions) - 1;
		if ($user_partitions >= $days) {
			$i = 0;
			while ($user_partitions > $days) {
				$oldest = $number_of_partitions[$i];
				cacti_log("SYSLOG: Removing old partition '" . $oldest['PARTITION_NAME'] . "'", false, 'SYSTEM');
				syslog_debug("Removing partition '" . $oldest['PARTITION_NAME'] . "'");
				syslog_db_execute("ALTER TABLE `" . $syslogdb_default . "`.`$table` DROP PARTITION " . $oldest['PARTITION_NAME']);
				$i++;
				$user_partitions--;
				$syslog_deleted++;
			}
		}
	}

	return $syslog_deleted;
}

function syslog_partition_check($table) {
	global $syslogdb_default;

	/* find date of last partition */
	$last_part = syslog_db_fetch_cell("SELECT PARTITION_NAME
		FROM `information_schema`.`partitions`
		WHERE table_schema='" . $syslogdb_default . "' AND table_name='syslog'
		ORDER BY partition_ordinal_position DESC
		LIMIT 1,1;");

	$lformat   = str_replace('d', '', $last_part);
	$cformat   = date('Ymd');

	if ($cformat > $lformat) {
		return true;
	} else {
		return false;
	}
}

function syslog_check_changed($request, $session) {
	if ((isset_request_var($request)) && (isset($_SESSION[$session]))) {
		if (get_request_var($request) != $_SESSION[$session]) {
			return 1;
		}
	}
}

function syslog_remove_items($table, $uniqueID) {
	global $config, $syslog_cnn, $syslog_incoming_config;

	include(SYSLOG_CONFIG);

	if ($table == 'syslog') {
		$rows = syslog_db_fetch_assoc("SELECT *
			FROM `" . $syslogdb_default . "`.`syslog_remove`
			WHERE enabled = 'on'
			AND id = $uniqueID");
	} else {
		$rows = syslog_db_fetch_assoc('SELECT *
			FROM `' . $syslogdb_default . '`.`syslog_remove`
			WHERE enabled="on"');
	}

	syslog_debug('Found   ' . sizeof($rows) . ',  Removal Rule(s) to process');

	$removed = 0;
	$xferred = 0;

	if ($table == 'syslog_incoming') {
		$total = syslog_db_fetch_cell('SELECT count(*)
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE status = ' . $uniqueID);
	} else {
		$total = 0;
	}

	if (cacti_sizeof($rows)) {
		foreach($rows as $remove) {
			$sql  = '';
			$sql1 = '';

			if ($remove['type'] == 'facility') {
				if ($table == 'syslog_incoming') {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
								FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
								INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ON sf.facility_id = si.facility_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ON sp.priority_id = si.priority_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
								ON spg.program = si.program
								INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
								ON sh.host = si.host
								WHERE ' . $syslog_incoming_config['facilityField'] . ' = ' . db_qstr($remove['message']) . '
								AND status = ' . $uniqueID . '
							) AS merge';
					}

					$sql = 'DELETE
						FROM `' . $syslogdb_default . '`.`syslog_incoming`
						WHERE ' . $syslog_incoming_config['facilityField'] . ' = ' . db_qstr($remove['message']) . '
						AND status = ' . $uniqueID;
				} else {
					$facility_id = syslog_db_fetch_cell('SELECT facility_id
						FROM `' . $syslogdb_default . '`.`syslog_facilities`
						WHERE facility = ' . db_qstr($remove['message']));

					if (!empty($facility_id)) {
						if ($remove['method'] != 'del') {
							$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT logtime, priority_id, facility_id, program_id, host_id, message
								FROM `' . $syslogdb_default . '`.`syslog`
								WHERE facility_id = ' . $facility_id;
						}

						$sql  = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
							WHERE facility_id = ' . $facility_id;
					}
				}
			} else if ($remove['type'] == 'program') {
				if ($table == 'syslog_incoming') {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
								FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
								INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ON sf.facility_id = si.facility_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ON sp.priority_id = si.priority_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
								ON spg.program = si.program
								INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
								ON sh.host = si.host
								WHERE program = ' . db_qstr($remove['message']) . '
								AND status = ' . $uniqueID . '
							) AS merge';
					}

					$sql = 'DELETE
						FROM `' . $syslogdb_default . '`.`syslog_incoming`
						WHERE `program` = ' . db_qstr($remove['message']) . '
						AND `status` = ' . $uniqueID;
				} else {
					$program_id = syslog_db_fetch_cell('SELECT program_id
						FROM `' . $syslogdb_default . '`.`syslog_programs`
						WHERE program = ' . db_qstr($remove['message']));

					if (!empty($program_id)) {
						if ($remove['method'] != 'del') {
							$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT logtime, priority_id, facility_id, program_id, host_id, message
								FROM `' . $syslogdb_default . '`.`syslog`
								WHERE program_id = ' . $program_id;
						}

						$sql  = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
							WHERE program_id = ' . $program_id;
					}
				}
			} elseif ($remove['type'] == 'host') {
				if ($table == 'syslog_incoming') {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
								FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
								INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ON sf.facility_id = si.facility_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ON sp.priority_id = si.priority_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
								ON spg.program = si.program
								INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
								ON sh.host = si.host
								WHERE host = ' . db_qstr($remove['message']) . '
								AND status = ' . $uniqueID . '
							) AS merge';
					}

					$sql = 'DELETE
						FROM `' . $syslogdb_default . '`.`syslog_incoming`
						WHERE host = ' . db_qstr($remove['message']) . '
						AND status = ' . $uniqueID;
				} else {
					$host_id = syslog_db_fetch_cell('SELECT host_id
						FROM `' . $syslogdb_default . '`.`syslog_hosts`
						WHERE host = ' . db_qstr($remove['message']));

					if (!empty($host_id)) {
						if ($remove['method'] != 'del') {
							$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT logtime, priority_id, facility_id, program_id, host_id, message
								FROM `' . $syslogdb_default . '`.`syslog`
								WHERE host_id = ' . $host_id;
						}

						$sql  = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
							WHERE host_id = ' . $host_id;
					}
				}
			} elseif ($remove['type'] == 'messageb') {
				if ($table == 'syslog_incoming') {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
								FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
								INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ON sf.facility_id = si.facility_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ON sp.priority_id = si.priority_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
								ON spg.program = si.program
								INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
								ON sh.host = si.host
								WHERE message LIKE ' . db_qstr($remove['message'] . '%') . '
								AND status = ' . $uniqueID . '
							) AS merge';
					}

					$sql = 'DELETE
						FROM `' . $syslogdb_default . '`.`syslog_incoming`
						WHERE message LIKE ' . db_qstr($remove['message'] . '%') . '
						AND status = ' . $uniqueID;
				} else {
					if ($remove['message'] != '') {
						if ($remove['method'] != 'del') {
							$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT logtime, priority_id, facility_id, program_id, host_id, message
								FROM `' . $syslogdb_default . '`.`syslog`
								WHERE message LIKE ' . db_qstr($remove['message'] . '%');
						}

						$sql  = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
							WHERE message LIKE ' . db_qstr($remove['message'] . '%');
					}
				}
			} elseif ($remove['type'] == 'messagec') {
				if ($table == 'syslog_incoming') {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
								FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
								INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ON sf.facility_id = si.facility_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ON sp.priority_id = si.priority_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
								ON spg.program = si.program
								INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
								ON sh.host = si.host
								WHERE message LIKE ' . db_qstr('%' . $remove['message'] . '%') . '
								AND status = ' . $uniqueID . '
							) AS merge';
					}

					$sql = 'DELETE
						FROM `' . $syslogdb_default . '`.`syslog_incoming`
						WHERE message LIKE ' . db_qstr('%' . $remove['message'] . '%') . '
						AND status = ' . $uniqueID;
				} else {
					if ($remove['message'] != '') {
						if ($remove['method'] != 'del') {
							$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT logtime, priority_id, facility_id, program_id, host_id, message
								FROM `' . $syslogdb_default . '`.`syslog`
								WHERE message LIKE ' . db_qstr('%' . $remove['message'] . '%');
						}

						$sql  = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
							WHERE message LIKE ' . db_qstr('%' . $remove['message'] . '%');
					}
				}
			} elseif ($remove['type'] == 'messagee') {
				if ($table == 'syslog_incoming') {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
								FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
								INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ON sf.facility_id = si.facility_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ON sp.priority_id = si.priority_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
								ON spg.program = si.program
								INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
								ON sh.host = si.host
								WHERE message LIKE ' . db_qstr('%' . $remove['message']) . '
								AND status = ' . $uniqueID . '
							) AS merge';
					}

					$sql = 'DELETE
						FROM `' . $syslogdb_default . '`.`syslog_incoming`
						WHERE message LIKE ' . db_qstr('%' . $remove['message']) . '
						AND status = ' . $uniqueID;
				} else {
					if ($remove['message'] != '') {
						if ($remove['method'] != 'del') {
							$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT logtime, priority_id, facility_id, program_id, host_id, message
								FROM `' . $syslogdb_default . '`.`syslog`
								WHERE message LIKE ' . db_qstr('%' . $remove['message']);
						}

						$sql  = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
							WHERE message LIKE ' . db_qstr('%' . $remove['message']);
					}
				}
			} elseif ($remove['type'] == 'sql') {
				if ($table == 'syslog_incoming') {
					if ($remove['method'] != 'del') {
						$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
							(logtime, priority_id, facility_id, program_id, host_id, message)
							SELECT logtime, priority_id, facility_id, program_id, host_id, message
							FROM (SELECT si.logtime, si.priority_id, si.facility_id, spg.program_id, sh.host_id, si.message
								FROM `' . $syslogdb_default . '`.`syslog_incoming` AS si
								INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
								ON sf.facility_id = si.facility_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_priorities` AS sp
								ON sp.priority_id = si.priority_id
								INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS spg
								ON spg.program = si.program
								INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
								ON sh.host = si.host
								WHERE status = ' . $uniqueID . '
							) AS merge
							WHERE (' . $remove['message'] . ')';
					}

					$sql = 'DELETE
						FROM `' . $syslogdb_default . '`.`syslog_incoming`
						WHERE (' . $remove['message'] . ')
						AND status = ' . $uniqueID;
				} else {
					if ($remove['message'] != '') {
						if ($remove['method'] != 'del') {
							$sql1 = 'INSERT INTO `' . $syslogdb_default . '`.`syslog_removed`
								(logtime, priority_id, facility_id, program_id, host_id, message)
								SELECT logtime, priority_id, facility_id, host_id, message
								FROM `' . $syslogdb_default . '`.`syslog`
								WHERE ' . $remove['message'];
						}

						$sql  = 'DELETE FROM `' . $syslogdb_default . '`.`syslog`
							WHERE ' . $remove['message'];
					}
				}
			}

			if ($sql != '' || $sql1 != '') {
				$debugm = '';
				/* process the removal rule first */
				if ($sql1 != '') {
					/* now delete the remainder that match */
					syslog_db_execute($sql1);
				}

				/* now delete the remainder that match */
				syslog_db_execute($sql);
				$removed += db_affected_rows($syslog_cnn);
				$debugm   = 'Deleted ' . $removed . ', ';
				if ($sql1 != '') {
					$xferred += db_affected_rows($syslog_cnn);
					$debugm   = 'Moved   ' . $xferred . ', ';
				}

				syslog_debug($debugm . ' Message' . (db_affected_rows($syslog_cnn) == 1 ? '' : 's' ) .
					" for removal rule '" . $remove['name'] . "'");
			}
		}
	}

	return array('removed' => $removed, 'xferred' => $xferred);
}

/** function syslog_log_row_color()
 *  This function set's the CSS for each row of the syslog table as it is displayed
 *  it supports both the legacy as well as the new approach to controlling these
 *  colors.
*/
function syslog_log_row_color($severity, $tip_title) {
	switch($severity) {
	case '':
	case '0':
		$class = 'logInfo';
		break;
	case '1':
		$class = 'logWarning';
		break;
	case '2':
		$class = 'logAlert';
		break;
	}

	print "<tr class='tableRow selectable $class'>\n";
}

/** function syslog_row_color()
 *  This function set's the CSS for each row of the syslog table as it is displayed
 *  it supports both the legacy as well as the new approach to controlling these
 *  colors.
*/
function syslog_row_color($priority, $message) {
	switch($priority) {
	case '0':
		$class = 'logEmergency';
		break;
	case '1':
		$class = 'logAlert';
		break;
	case '2':
		$class = 'logCritical';
		break;
	case '3':
		$class = 'logError';
		break;
	case '4':
		$class = 'logWarning';
		break;
	case '5':
		$class = 'logNotice';
		break;
	case '6':
		$class = 'logInfo';
		break;
	case '7':
		$class = 'logDebug';
		break;
	}

	print "<tr title='" . html_escape($message) . "' class='tableRow selectable $class syslogRow'>";
}

function sql_hosts_where($tab) {
	global $hostfilter, $hostfilter_log, $syslog_incoming_config;

	$hostfilter     = '';
	$hostfilter_log = '';
	$hosts_array    = array();

	include(SYSLOG_CONFIG);

	if (!isempty_request_var('host') && get_nfilter_request_var('host') != 'null') {
		$hostarray = explode(',', trim(get_nfilter_request_var('host')));
		if ($hostarray[0] != '0') {
			foreach($hostarray as $host_id) {
				input_validate_input_number($host_id);

				if ($host_id > 0) {
					$log_host = syslog_db_fetch_cell_prepared('SELECT host
						FROM `' . $syslogdb_default . '`.`syslog_hosts`
						WHERE host_id = ?',
						array($host_id));

					if (!empty($log_host)) {
						$hosts_array[] = db_qstr($log_host);
					}
				}
			}

			if (cacti_sizeof($hosts_array)) {
				$hostfilter_log = ' host IN(' . implode(',', $hosts_array) . ')';
			}

			$hostfilter .= (strlen($hostfilter) ? ' AND ':'') . ' host_id IN(' . implode(',', $hostarray) . ')';
		}
	}
}

function syslog_export($tab) {
	global $syslog_incoming_config, $severities;

	include(SYSLOG_CONFIG);

	if ($tab == 'syslog') {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=syslog_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		$hosts = array_rekey(
			syslog_db_fetch_assoc('SELECT host_id, host
				FROM `' . $syslogdb_default . '`.`syslog_hosts`'),
			'host_id', 'host'
		);

		$facilities = array_rekey(
			syslog_db_fetch_assoc('SELECT facility_id, facility
				FROM `' . $syslogdb_default . '`.`syslog_facilities`'),
			'facility_id', 'facility'
		);

		$priorities = array_rekey(
			syslog_db_fetch_assoc('SELECT priority_id, priority
				FROM `' . $syslogdb_default . '`.`syslog_priorities`'),
			'priority_id', 'priority'
		);

		$programs = array_rekey(
			syslog_db_fetch_assoc('SELECT program_id, program
				FROM `' . $syslogdb_default . '`.`syslog_programs`'),
			'program_id', 'program'
		);

		print 'host, facility, priority, program, date, message' . "\r\n";

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($facilities[$message['facility_id']])) {
					$facility = $facilities[$message['facility_id']];
				} else {
					$facility = 'Unknown';
				}

				if (isset($programs[$message['program_id']])) {
					$program = $programs[$message['program_id']];
				} else {
					$program = 'Unknown';
				}

				if (isset($priorities[$message['priority_id']])) {
					$priority = $priorities[$message['priority_id']];
				} else {
					$priority = 'Unknown';
				}

				if (isset($hosts[$message['host_id']])) {
					$host = $hosts[$message['host_id']];
				} else {
					$host = 'Unknown';
				}

				print
					'"' .
					$host                                          . '","' .
					ucfirst($facility)                             . '","' .
					ucfirst($priority)                             . '","' .
					ucfirst($program)                              . '","' .
					$message['logtime']                            . '","' .
					$message[$syslog_incoming_config['textField']] . '"'   . "\r\n";
			}
		}
	} else {
		header('Content-type: application/excel');
		header('Content-Disposition: attachment; filename=alert_log_view-' . date('Y-m-d',time()) . '.csv');

		$sql_where  = '';
		$messages   = get_syslog_messages($sql_where, 100000, $tab);

		print 'name, severity, date, message, host, facility, priority, count' . "\r\n";

		if (cacti_sizeof($messages)) {
			foreach ($messages as $message) {
				if (isset($severities[$message['severity']])) {
					$severity = $severities[$message['severity']];
				} else {
					$severity = 'Unknown';
				}

				print
					'"' .
					$message['name']                  . '","' .
					$severity                         . '","' .
					$message['logtime']               . '","' .
					$message['logmsg']                . '","' .
					$message['host']                  . '","' .
					ucfirst($message['facility'])     . '","' .
					ucfirst($message['priority'])     . '","' .
					$message['count']                 . '"'   . "\r\n";
			}
		}
	}
}

function syslog_debug($message) {
	global $debug;

	if ($debug) {
		print 'SYSLOG DEBUG: ' . trim($message) . PHP_EOL;
	}
}

function syslog_log_alert($alert_id, $alert_name, $severity, $msg, $count = 1, $html = '', $hosts = array()) {
	global $config, $severities;

	include(SYSLOG_CONFIG);

	if ($count <= 1) {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = $msg['seq'];
		$save['logtime']     = $msg['logtime'];
		$save['logmsg']      = db_qstr($msg['message']);
		$save['host']        = $msg['host'];
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = 1;
		$save['html']        = db_qstr($html);

		$id = 0;
		$id = syslog_sql_save($save, '`' . $syslogdb_default . '`.`syslog_logs`', 'seq');

		$save['seq']        = $id;
		$save['alert_name'] = $alert_name;
		api_plugin_hook_function('syslog_update_hostsalarm', $save);

		cacti_log("WARNING: The Syslog Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered on Host '" . $msg['host'] . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	} else {
		$save['seq']         = '';
		$save['alert_id']    = $alert_id;
		$save['logseq']      = 0;
		$save['logtime']     = date('Y-m-d H:i:s');
		$save['logmsg']      = db_qstr($alert_name);
		$save['host']        = 'N/A';
		$save['facility_id'] = $msg['facility_id'];
		$save['priority_id'] = $msg['priority_id'];
		$save['count']       = $count;
		$save['html']        = db_qstr($html);

		$id = 0;
		$id = syslog_sql_save($save, '`' . $syslogdb_default . '`.`syslog_logs`', 'seq');

		$save['seq']         = $id;
		$save['alert_name']  = $alert_name;

		if (cacti_sizeof($hosts)) {
			foreach($hosts as $host) {
				$save['host'] = $host;
				api_plugin_hook_function('syslog_update_hostsalarm', $save);
			}
		}

		cacti_log("WARNING: The Syslog Intance Alert '$alert_name' with Severity '" . $severities[$severity] . "', has been Triggered, Count was '" . $count . "', and Sequence '$id'", false, 'SYSLOG');

		return $id;
	}
}

function syslog_manage_items($from_table, $to_table) {
	global $config, $syslog_cnn, $syslog_incoming_config;

	include(SYSLOG_CONFIG);

	/* Select filters to work on */
	$rows = syslog_db_fetch_assoc('SELECT * FROM `' . $syslogdb_default . "`.`syslog_remove` WHERE enabled='on'");

	syslog_debug('Found   ' . sizeof($rows) .  ',  Removal Rule(s)' .  ' to process');

	$removed = 0;
	$xferred = 0;
	$total   = 0;

	if (cacti_sizeof($rows)) {
		foreach($rows as $remove) {
			syslog_debug('Processing Rule  - ' . $remove['message']);

			$sql_sel = '';
			$sql_dlt = '';

			if ($remove['type'] == 'facility') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `" . $syslogdb_default . "`. $from_table
						WHERE facility_id IN
							(SELECT distinct facility_id FROM `". $syslogdb_default . "`syslog_facilities
							WHERE facility ='". $remove['message']."')";
				} else {
					$sql_dlt = "DELETE FROM `" . $syslogdb_default . "`. $from_table
						WHERE facility_id IN
							(SELECT distinct facility_id FROM `". $syslogdb_default . "`syslog_facilities
							WHERE facility ='". $remove['message']."')";
				}

			} elseif ($remove['type'] == 'host') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq
						FROM `" . $syslogdb_default . "`. $from_table
						WHERE host_id in
							(SELECT distinct host_id FROM `". $syslogdb_default . "`syslog_hosts
							WHERE host ='". $remove['message']."')";
				} else {
					$sql_dlt = "DELETE FROM `" . $syslogdb_default . "`. $from_table
						WHERE host_id in
							(SELECT distinct host_id FROM `". $syslogdb_default . "`syslog_hosts
							WHERE host ='". $remove['message']."')";
				}
			} elseif ($remove['type'] == 'messageb') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `" . $syslogdb_default . "`. $from_table
						WHERE message LIKE '" . $remove['message'] . "%' ";
				} else {
					$sql_dlt = "DELETE FROM `" . $syslogdb_default . "`. $from_table
						WHERE message LIKE '" . $remove['message'] . "%' ";
				}

			} elseif ($remove['type'] == 'messagec') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `" . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "%' ";
				} else {
					$sql_dlt = "DELETE FROM `" . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "%' ";
				}
			} elseif ($remove['type'] == 'messagee') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `" . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "' ";
				} else {
					$sql_dlt = "DELETE FROM `" . $syslogdb_default . "`. $from_table
						WHERE message LIKE '%" . $remove['message'] . "' ";
				}
			} elseif ($remove['type'] == 'sql') {
				if ($remove['method'] != 'del') {
					$sql_sel = "SELECT seq FROM `" . $syslogdb_default . "`. $from_table
						WHERE message (" . $remove['message'] . ") ";
				} else {
					$sql_dlt = "DELETE FROM `" . $syslogdb_default . "`. $from_table
						WHERE message (" . $remove['message'] . ") ";
				}
			}

			if ($sql_sel != '' || $sql_dlt != '') {
				$debugm = '';
				/* process the removal rule first */
				if ($sql_sel != '') {
					$move_count = 0;
					/* first insert, then delete */
					$move_records = syslog_db_fetch_assoc($sql_sel);
					syslog_debug("Found   ". sizeof($move_records) . " Message(s)");

					if (cacti_sizeof($move_records)) {
						$all_seq = '';
						$messages_moved = 0;
						foreach($move_records as $move_record) {
							$all_seq = $all_seq . ", " . $move_record['seq'];
						}

						$all_seq = preg_replace('/^,/i', '', $all_seq);
						syslog_db_execute("INSERT INTO `". $syslogdb_default . "`.`". $to_table ."`
							(facility_id, priority_id, host_id, logtime, message)
							(SELECT facility_id, priority_id, host_id, logtime, message
							FROM `". $syslogdb_default . "`.". $from_table ."
							WHERE seq in (" . $all_seq ."))");

						$messages_moved = db_affected_rows($syslog_cnn);

						if ($messages_moved > 0) {
							syslog_db_execute("DELETE FROM `". $syslogdb_default . "`.`" . $from_table ."`
								WHERE seq in (" . $all_seq .")" );
						}

						$xferred += $messages_moved;
						$move_count = $messages_moved;
					}
					$debugm   = "Moved   " . $move_count . " Message(s)";
				}

				if ($sql_dlt != '') {
					/* now delete the remainder that match */
					syslog_db_execute($sql_dlt);
					$removed += db_affected_rows($syslog_cnn);
					$debugm   = "Deleted " . $removed . " Message(s)";
				}

				syslog_debug($debugm);
			}
		}
	}

	return array('removed' => $removed, 'xferred' => $xferred);
}

/* get_hash_syslog - returns the current unique hash for an alert
   @arg $id - (int) the ID of the syslog item to return a hash for
   @returns - a 128-bit, hexadecimal hash */
function get_hash_syslog($id, $table) {
    $hash = syslog_db_fetch_cell_prepared('SELECT hash
		FROM ' . $table . '
		WHERE id = ?',
		array($id));

	if (empty($hash)) {
        return generate_hash();
	} elseif (preg_match('/[a-fA-F0-9]{32}/', $hash)) {
        return $hash;
    } else {
        return generate_hash();
    }
}

function syslog_ia2xml($array) {
	$xml = '';

	if (cacti_sizeof($array)) {
		foreach ($array as $key=>$value) {
			if (is_array($value)) {
				$xml .= "\t<$key>" . syslog_ia2xml($value) . "</$key>\n";
			} else {
				$xml .= "\t<$key>" . html_escape($value) . "</$key>\n";
			}
		}
	}

	return $xml;
}

function syslog_array2xml($array, $tag = 'template') {
	static $index = 1;

	$xml = "<$tag$index>\n" . syslog_ia2xml($array) . "</$tag$index>\n";

	$index++;

	return $xml;
}

/**
 * syslog_process_alerts - Process each of the Syslog Alerts
 *
 * Syslog Alerts come in essentially 4 types
 *
 * System Wide non-threshold alerts - These alerts are simply alerts that match the pattern defined by the alert
 * System Wide threshold alerts     - These alerts are syslog messages that both match the pattern and have more than the
 *                                    threshold amount that take place every collector cycle (30 seconds, 1 minutes, 5 minutes, etc)
 * Host based non-threshold alerts  - Alerts that happen on a per host basis, so you can alert for each host that the syslog message
 *                                    occurred to.
 * Host based threshold alerts      - Like the sytem level alert, it's an alert that happens more than x times per host.
 *
 * The advantage and reason for having host based alerts is that it allows you to target ticket generation for a specific host
 * and more importantly, to be able to have a separate re-alert cycles for that very same message as there can be similar messages
 * happening all the time at the system level, so it's hard to target a single host for re-alert rules.
 *
 * @param  (int)   The unique id to process
 *
 * @return (array) An array of the number of alerts processed and the number of alerts generated
 */
function syslog_process_alerts($uniqueID) {
	global $syslogdb_default;

	include(SYSLOG_CONFIG);

	$syslog_alarms = 0;
	$syslog_alerts = 0;

	/* send out the alerts */
	$alerts = syslog_db_fetch_assoc('SELECT *
		FROM `' . $syslogdb_default . "`.`syslog_alert`
		WHERE enabled='on'");

	if (cacti_sizeof($alerts)) {
		$syslog_alerts = cacti_sizeof($alerts);
	}

	syslog_debug('Found   ' . $syslog_alerts . ',  Alert Rule' . ($syslog_alerts == 1 ? '' : 's' ) . ' to process');

	if (cacti_sizeof($alerts)) {
		foreach($alerts as $alert) {
			$sql      = '';
			$th_sql   = '';

			/* we roll up statistics depending on the level */
			if ($alert['level'] == 1) {
				$groupBy = ' GROUP BY host';
			} else {
				$groupBy = '';
			}

			if ($alert['type'] == 'facility') {
				$sql = 'SELECT *
					FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE ' . $syslog_incoming_config['facilityField'] . ' = ' . db_qstr($alert['message']) . '
					AND status = ' . $uniqueID . $groupBy;
			} elseif ($alert['type'] == 'messageb') {
				$sql = 'SELECT *
					FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE ' . $syslog_incoming_config['textField'] . '
					LIKE ' . db_qstr($alert['message'] . '%') . '
					AND status = ' . $uniqueID . $groupBy;
			} elseif ($alert['type'] == 'messagec') {
				$sql = 'SELECT *
					FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE ' . $syslog_incoming_config['textField'] . '
					LIKE ' . db_qstr('%' . $alert['message'] . '%') . '
					AND status = ' . $uniqueID . $groupBy;
			} elseif ($alert['type'] == 'messagee') {
				$sql = 'SELECT *
					FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE ' . $syslog_incoming_config['textField'] . '
					LIKE ' . db_qstr('%' . $alert['message']) . '
					AND status = ' . $uniqueID . $groupBy;
			} elseif ($alert['type'] == 'host') {
				$sql = 'SELECT *
					FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE ' . $syslog_incoming_config['hostField'] . ' = ' . db_qstr($alert['message']) . '
					AND status = ' . $uniqueID . $groupBy;
			} elseif ($alert['type'] == 'sql') {
				$sql = 'SELECT *
					FROM `' . $syslogdb_default . '`.`syslog_incoming`
					WHERE (' . $alert['message'] . ')
					AND status=' . $uniqueID . $groupBy;
			}

			if ($sql != '') {
				if ($alert['method'] == '1') {
					if ($alert['level'] == 1) {
						$th_sql = str_replace('*', 'host, COUNT(*)', $sql);
					} else {
						$th_sql = str_replace('*', 'COUNT(*)', $sql);
					}

					$count = syslog_db_fetch_cell($th_sql);
				} else {
					$count = 0;
				}

				$syslog_alarms += syslog_process_alert($alert, $sql, $count);
			}
		}
	}

	return array('syslog_alerts' => $syslog_alerts, 'syslog_alarms' => $syslog_alarms);
}

/**
 * syslog_process_alert - Process the Alert and generate notifications, execute commands, etc.
 *
 * @param  (array)  The alert to process
 * @param  (string) The SQL to search for the Alert
 * @param  (int)    In the case of a threshold alert, the number of occurrents
 *                  of hosts with occurrences that were encounted through
 *                  pre-processing the message
 */
function syslog_process_alert($alert, $sql, $count) {
	$htmlm    = '';
	$alertm   = '';
	$smsalert = '';

	$syslog_alarms = 0;

	if (read_config_option('syslog_html') == 'on') {
		$html = true;
	} else {
		$html = false;
	}

	$from_email = read_config_option('settings_from_email');
	if ($from_email == '') {
		$from_email = 'Cacti@cacti.net';
	}

	$from_name  = read_config_option('settings_from_name');
	if ($from_name == '') {
		$from_name = 'Cacti Reporting';
	}

	$from = array($from_email, $from_name);

	if (($alert['method'] == '1' && $count >= $alert['num']) || ($alert['method'] == '0')) {
		$at = syslog_db_fetch_assoc($sql);

		/* get a date for the repeat alert */
		if ($alert['repeat_alert']) {
			$date = date('Y-m-d H:i:s', time() - ($alert['repeat_alert'] * read_config_option('poller_interval')));
		}

		if (cacti_sizeof($at)) {
			$htmlm .= "<html><head><style type='text/css'>";
			$htmlm .= file_get_contents($config['base_path'] . '/plugins/syslog/syslog.css');
			$htmlm .= '</style></head>';

			if ($alert['method'] == '1') {
				$alertm .= "-----------------------------------------------\n";
				$alertm .= __('WARNING: A Syslog Plugin Instance Count Alert has Been Triggered', 'syslog') . "\n";
				$alertm .= __('Name:', 'syslog')           . ' ' . html_escape($alert['name']) . "\n";
				$alertm .= __('Severity:', 'syslog')       . ' ' . $severities[$alert['severity']] . "\n";
				$alertm .= __('Threshold:', 'syslog')      . ' ' . $alert['num'] . "\n";
				$alertm .= __('Count:', 'syslog')          . ' ' . sizeof($at)       . "\n";
				$alertm .= __('Message String:', 'syslog') . ' ' . html_escape($alert['message']) . "\n";

				$htmlm  .= '<body><h1>' . __esc('Cacti Syslog Plugin Threshold Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
				$htmlm  .= '<table cellspacing="0" cellpadding="3" border="1">';
				$htmlm  .= '<tr><th>' . __('Alert Name', 'syslog') . '</th><th>' . __('Severity', 'syslog') . '</th><th>' . __('Threshold', 'syslog') . '</th><th>' . __('Count', 'syslog') . '</th><th>' . __('Match String', 'syslog') . '</th></tr>';
				$htmlm  .= '<tr><td>' . html_escape($alert['name']) . '</td>';
				$htmlm  .= '<td>'     . $severities[$alert['severity']]  . '</td>';
				$htmlm  .= '<td>'     . $alert['num']     . '</td>';
				$htmlm  .= '<td>'     . sizeof($at)       . '</td>';
				$htmlm  .= '<td>'     . html_escape($alert['message']) . '</td></tr></table><br>';
			} else {
				$htmlm .= '<body><h1>' . __esc('Cacti Syslog Plugin Alert \'%s\'', $alert['name'], 'syslog') . '</h1>';
			}

			$htmlm .= '<table>';
			$htmlm .= '<tr><th>' . __('Hostname', 'syslog') . '</th><th>' . __('Date', 'syslog') . '</th><th>' . __('Severity', 'syslog') . '</th><th>' . __('Level', 'syslog') . '</th><th>' . __('Message', 'syslog') . '</th></tr>';

			$max_alerts  = read_config_option('syslog_maxrecords');
			$alert_count = 0;
			$htmlh       = $htmlm;
			$alerth      = $alertm;
			$hostlist    = array();
			foreach($at as $a) {
				$a['message'] = str_replace('  ', "\n", $a['message']);
				$a['message'] = trim($a['message']);

				if (($alert['method'] == 1 && $alert_count < $max_alerts) || $alert['method'] == 0) {
					if ($alert['method'] == 0) {
						$alertm  = $alerth;
					}

					$alertm .= "-----------------------------------------------\n";
					$alertm .= __('Hostname:', 'syslog') . ' ' . html_escape($a['host']) . "\n";
					$alertm .= __('Date:', 'syslog')     . ' ' . $a['logtime'] . "\n";
					$alertm .= __('Severity:', 'syslog') . ' ' . $severities[$alert['severity']] . "\n\n";
					$alertm .= __('Level:', 'syslog')    . ' ' . $syslog_levels[$a['priority_id']] . "\n\n";
					$alertm .= __('Message:', 'syslog')  . ' ' . "\n" . html_escape($a['message']) . "\n";

					if ($alert['method'] == 0) {
						$htmlm = $htmlh;
					}

					$htmlm  .= '<tr><td>' . $a['host']                        . '</td>';
					$htmlm  .= '<td>'     . $a['logtime']                     . '</td>';
					$htmlm  .= '<td>'     . $severities[$alert['severity']]   . '</td>';
					$htmlm  .= '<td>'     . $syslog_levels[$a['priority_id']] . '</td>';
					$htmlm  .= '<td>'     . html_escape($a['message']) . '</td></tr>';
				}

				$syslog_alarms++;
				$alert_count++;

				$ignore = false;
				if ($alert['method'] != '1') {
					if ($alert['repeat_alert'] > 0) {
						$ignore = syslog_db_fetch_cell_prepared('SELECT COUNT(*)
							FROM syslog_logs
							WHERE alert_id = ?
							AND logtime > ?
							AND host = ?',
							array($alert['id'], $data, $a['host']));
					}

					if (!$ignore) {
						$hostlist[] = $a['host'];

						$htmlm  .= '</table></body></html>';

						$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $a, 1, $htmlm);

						$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Host:', 'syslog') . $a['host'] . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;

						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), ($html ? $htmlm:$alertm), $smsalert);

						if ($alert['open_ticket'] == 'on' && strlen(read_config_option('syslog_ticket_command'))) {
							if (is_executable(read_config_option('syslog_ticket_command'))) {
								alert_setup_environment($alert, $a, $hostlist);
								putenv('ALERT_NAME='     . cacti_escapeshellarg(clean_up_name($alert['name'])));
								putenv('ALERT_SEVERITY=' . cacti_escapeshellarg($alert['severity']));
								putenv('ALERT_HOSTLIST=' . cacti_escapeshellarg(implode(',', $hostlist)));
								putenv('ALERT_MESSAGE='  . cacti_escapeshellarg($alert['message']));

								$command = read_config_option('syslog_ticket_command') .
									' --alert-name=' . cacti_escapeshellarg(clean_up_name($alert['name'])) .
									' --severity='   . cacti_escapeshellarg($alert['severity']) .
									' --hostlist='   . cacti_escapeshellarg(implode(',',$hostlist)) .
									' --message='    . cacti_escapeshellarg($alert['message']);

								$output = array();
								$return = 0;

								exec($command, $output, $return);

								if ($return != 0) {
									cacti_log(sprintf('ERROR: Command Failed.  Alert:%s, Exit:%s, Output:%s', $alert['name'], $return, implode(', ', $output)), false, 'SYSLOG');
								}
							}
						}
					}
				} else {
					/* get a list of hosts impacted */
					$hostlist[] = $a['host'];
				}

				if (trim($alert['command']) != '' && !$ignore) {
					alert_setup_environment($alert, $a, $hostlist);

					$command = alert_replace_variables($alert, $a);

					cacti_log("SYSLOG NOTICE: Executing '$command'", true, 'SYSTEM');

					exec_background('/bin/sh', $command);
				}
			}

			$htmlm  .= '</table></body></html>';
			$alertm .= "-----------------------------------------------\n\n";

			if ($alert['method'] == 1) {
				//The syslog_sendemail should be called prior to syslog_log_alert, otherwise, the $found always larger than 0
				if ($alertm != '') {
					$resend = true;
					if ($alert['repeat_alert'] > 0) {
						$found = syslog_db_fetch_cell('SELECT count(*)
							FROM syslog_logs
							WHERE alert_id=' . $alert['id'] . "
							AND logtime>'$date'");

						if ($found) $resend = false;
					}

					if ($resend) {
						syslog_sendemail(trim($alert['email']), $from, __esc('Event Alert - %s', $alert['name'], 'syslog'), ($html ? $htmlm:$alertm), $smsalert);

						if ($alert['open_ticket'] == 'on' && strlen(read_config_option('syslog_ticket_command'))) {
							if (is_executable(read_config_option('syslog_ticket_command'))) {
								alert_setup_environment($alert, $a, $hostlist);
								putenv('ALERT_NAME='     . cacti_escapeshellarg(clean_up_name($alert['name'])));
								putenv('ALERT_SEVERITY=' . cacti_escapeshellarg($alert['severity']));
								putenv('ALERT_HOSTLIST=' . cacti_escapeshellarg(implode(',', $hostlist)));
								putenv('ALERT_MESSAGE='  . cacti_escapeshellarg($alert['message']));

								$command = read_config_option('syslog_ticket_command') .
									' --alert-name=' . cacti_escapeshellarg(clean_up_name($alert['name'])) .
									' --severity='   . cacti_escapeshellarg($alert['severity']) .
									' --hostlist='   . cacti_escapeshellarg(implode(',',$hostlist)) .
									' --message='    . cacti_escapeshellarg($alert['message']);

								$output = array();
								$return = 0;

								exec($command, $output, $return);

								if ($return != 0) {
									cacti_log(sprintf('ERROR: Command Failed.  Alert:%s, Exit:%s, Output:%s', $alert['name'], $return, implode(', ', $output)), false, 'SYSLOG');
								}
							}
						}
					}
				}

				$sequence = syslog_log_alert($alert['id'], $alert['name'], $alert['severity'], $at[0], sizeof($at), $htmlm, $hostlist);
				$smsalert = __('Sev:', 'syslog') . $severities[$alert['severity']] . __(', Count:', 'syslog') . sizeof($at) . __(', URL:', 'syslog') . read_config_option('base_url', true) . '/plugins/syslog/syslog.php?tab=current&id=' . $sequence;
			}

			syslog_debug("Alert Rule '" . $alert['name'] . "' has been activated");
		}
	}

	return $syslog_alarms;
}

/**
 * syslog_preprocess_incoming_records - Generate a uniqueID to allow moving of
 *   records to done table and mark incoming records with the uniqueID and
 *   then if syslog is configured to strip domains, perform that first.
 *
 * @return (int) Unique id to allow syslog messages that come in randomly to
 *               be differentiate between messages to process and messages
 *               to be left till then ext polling cycle.
 */
function syslog_preprocess_incoming_records() {
	global $syslogdb_default;

	while (1) {
		$uniqueID = rand(1, 127);

		$count = syslog_db_fetch_cell('SELECT count(*)
			FROM `' . $syslogdb_default . '`.`syslog_incoming`
			WHERE status=' . $uniqueID);

		if ($count == 0) {
			break;
		}
	}

	/* flag all records with the uniqueID prior to moving */
	syslog_db_execute('UPDATE `' . $syslogdb_default . '`.`syslog_incoming`
		SET status = ' . $uniqueID . '
		WHERE status = 0');

	syslog_debug('Unique ID = ' . $uniqueID);

	$syslog_incoming = db_fetch_cell('SELECT COUNT(seq)
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE status = ' . $uniqueID);

	syslog_debug('Found   ' . $syslog_incoming .  ', New Message(s) to process');

	/* strip domains if we have requested to do so */
	syslog_strip_incoming_domains($uniqueID);

	api_plugin_hook('plugin_syslog_before_processing');

	return array('uniqueID' => $uniqueID, 'incoming' => $syslog_incoming);
}

/**
 * syslog_strip_incoming_domains - If syslog is setup to strip DNS domain name suffixes do that
 *   prior to processing the records.
 *
 * @param  (string) The uniqueID records to process
 *
 * @return (void)
 */
function syslog_strip_incoming_domains($uniqueID) {
	$syslog_domains = read_config_option('syslog_domains');
	if ($syslog_domains != '') {
		$domains = explode(',', trim($syslog_domains));

		foreach($domains as $domain) {
			syslog_db_execute('UPDATE `' . $syslogdb_default . "`.`syslog_incoming`
				SET host = SUBSTRING_INDEX(host, '.', 1)
				WHERE host LIKE '%$domain'
				AND uniqueID = $uniqueID");
		}
	}
}

/**
 * syslog_update_reference_tables - There are many values in the syslog plugin
 *   that for the purposes of reducing the size of the syslog table are normalized
 *   the columns includes the facility, the priority, and the hostname.
 *
 *   This function will add those new hostnames to the various reference tables
 *   and assign an id to each of them.  This way the syslog table can be optimized
 *   for size as much as possible.
 *
 * @param  (int)  The unique id for syslog_incoming messages to process
 *
 * @return (void)
 */
function syslog_update_reference_tables($uniqueID) {
	global $syslogdb_default;

	/* correct for invalid hosts */
	if (read_config_option('syslog_validate_hostname') == 'on') {
		$hosts = syslog_db_fetch_assoc('SELECT DISTINCT host
			FROM `' . $syslogdb_default . '`.`syslog_incoming`');

		foreach($hosts as $host) {
			if ($host['host'] == gethostbyname($host['host'])) {
				syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . "`.`syslog_incoming`
					SET host = 'invalid_host'
					WHERE host = ?",
					array($host['host']));
			}
		}
	}

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_programs`
		(program, last_updated)
		SELECT DISTINCT program, NOW()
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE status = ?
		ON DUPLICATE KEY UPDATE
			program=VALUES(program),
			last_updated=VALUES(last_updated)',
		array($uniqueID));

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_hosts`
		(host, last_updated)
		SELECT DISTINCT host, NOW() AS last_updated
		FROM `' . $syslogdb_default . '`.`syslog_incoming`
		WHERE status = ?
		ON DUPLICATE KEY UPDATE
			host=VALUES(host),
			last_updated=NOW()',
		array($uniqueID));

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_host_facilities`
		(host_id, facility_id)
		SELECT host_id, facility_id
		FROM (
			(
				SELECT DISTINCT host, facility_id
				FROM `' . $syslogdb_default . "`.`syslog_incoming`
				WHERE status = ?
			) AS s
			INNER JOIN `" . $syslogdb_default . '`.`syslog_hosts` AS sh
			ON s.host = sh.host
		)
		ON DUPLICATE KEY UPDATE
			host_id=VALUES(host_id),
			last_updated=NOW()',
		array($uniqueID));
}

function syslog_update_statistics($uniqueID) {
	global $syslogdb_default, $syslog_cnn;

	if (read_config_option('syslog_statistics') == 'on') {
		syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog_statistics`
			(host_id, facility_id, priority_id, program_id, insert_time, records)
			SELECT host_id, facility_id, priority_id, program_id, NOW(), SUM(records) AS records
			FROM (SELECT host_id, facility_id, priority_id, program_id, COUNT(*) AS records
				FROM syslog_incoming AS si
				INNER JOIN syslog_hosts AS sh
				ON sh.host=si.host
				INNER JOIN syslog_programs AS sp
				ON sp.program=si.program
				WHERE status = ?
				GROUP BY host_id, priority_id, facility_id, program_id) AS merge
			GROUP BY host_id, priority_id, facility_id, program_id',
			array($uniqueID));

		$stats = db_affected_rows($syslog_cnn);

		syslog_debug('Stats   ' . $stats . ",  Record(s) to the 'syslog_statistics' table");
	}
}

/**
 * syslog_incoming_to_syslog - Move incoming syslog records to the syslog table
 *
 * Once all Alerts have been processed, we need to move entries first to
 * the syslog table, and then after which we can perform various
 * removal rules against them.
 *
 * @param  (int) The unique id for rows in the syslog table
 *
 * @return (int) The number of rows moved to the syslog table
 */
function syslog_incoming_to_syslog($uniqueID) {
	global $syslogdb_default, $syslog_cnn;

	syslog_db_execute_prepared('INSERT INTO `' . $syslogdb_default . '`.`syslog`
		(logtime, priority_id, facility_id, program_id, host_id, message)
		SELECT logtime, priority_id, facility_id, program_id, host_id, message
		FROM (
			SELECT logtime, priority_id, facility_id, sp.program_id, sh.host_id, message
			FROM syslog_incoming AS si
			INNER JOIN syslog_hosts AS sh
			ON sh.host = si.host
			INNER JOIN syslog_programs AS sp
			ON sp.program = si.program
			WHERE status = ?
		) AS merge',
		array($uniqueID));

	$moved = db_affected_rows($syslog_cnn);

	syslog_debug('Moved   ' . $moved . ",  Message(s) to the 'syslog' table");

	syslog_db_execute('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE status=' . $uniqueID);

	syslog_debug('Deleted ' . db_affected_rows($syslog_cnn) . ',  Already Processed Message(s) from incoming');

	syslog_db_execute('DELETE FROM `' . $syslogdb_default . '`.`syslog_incoming` WHERE logtime < DATE_SUB(NOW(), INTERVAL 1 HOUR)');

	$stale = db_affected_rows($syslog_cnn);

	syslog_debug('Deleted ' . $stale . ',  Stale Message(s) from incoming');

	return array('moved' => $moved, 'stale' => $stale);
}

/**
 * syslog_postprocess_tables - Remove stale records and optimize tables after
 *   message processing has been completed.
 *
 * @return (void)
 */
function syslog_postprocess_tables() {
	global $syslogdb_default, $syslog_cnn;

	$delete_date = date('Y-m-d H:i:s', time() - (read_config_option('syslog_retention')*86400));

	/* remove stats messages */
	if (read_config_option('syslog_statistics') == 'on') {
		if (read_config_option('syslog_retention') > 0) {
			syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_statistics`
				WHERE insert_time < ?',
				array($delete_date));

			syslog_debug('Deleted ' . db_affected_rows($syslog_cnn) . ',  Syslog Statistics Record(s)');
		}
	} else {
		syslog_db_execute('TRUNCATE `' . $syslogdb_default . '`.`syslog_statistics`');
	}

	/* remove alert log messages */
	if (read_config_option('syslog_alert_retention') > 0) {
		api_plugin_hook_function('syslog_delete_hostsalarm', $delete_date);

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_logs`
			WHERE logtime < ?',
			array($delete_date));

		syslog_debug('Deleted ' . db_affected_rows($syslog_cnn) . ',  Syslog alarm log Record(s)');

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_hosts`
			WHERE last_updated < ?',
			array($delete_date));

		syslog_debug('Deleted ' . db_affected_rows($syslog_cnn) . ',  Syslog Host Record(s)');

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_programs`
			WHERE last_updated < ?',
			array($delete_date));

		syslog_debug('Deleted ' . db_affected_rows($syslog_cnn) . ',  Old programs from programs table');

		syslog_db_execute_prepared('DELETE FROM `' . $syslogdb_default . '`.`syslog_host_facilities`
			WHERE last_updated < ?',
			array($delete_date));

		syslog_debug('Deleted ' . db_affected_rows($syslog_cnn) . ',  Syslog Host/Facility Record(s)');
	}

	/* OPTIMIZE THE TABLES ONCE A DAY, JUST TO HELP CLEANUP */
	if (date('G') == 0 && date('i') < 5) {
		syslog_debug('Optimizing Tables');
		if (!syslog_is_partitioned()) {
			syslog_db_execute('OPTIMIZE TABLE
				`' . $syslogdb_default . '`.`syslog_incoming`,
				`' . $syslogdb_default . '`.`syslog`,
				`' . $syslogdb_default . '`.`syslog_remove`,
				`' . $syslogdb_default . '`.`syslog_removed`,
				`' . $syslogdb_default . '`.`syslog_alert`');
		} else {
			syslog_db_execute('OPTIMIZE TABLE
				`' . $syslogdb_default . '`.`syslog_incoming`,
				`' . $syslogdb_default . '`.`syslog_remove`,
				`' . $syslogdb_default . '`.`syslog_alert`');
		}
	}
}

function syslog_process_reports() {
	global $syslogdb_default, $syslog_cnn, $forcer;

	syslog_debug('Processing Reports...');

	/* Lets run the reports */
	$reports = syslog_db_fetch_assoc('SELECT *
		FROM `' . $syslogdb_default . "`.`syslog_reports`
		WHERE enabled='on'");

	$syslog_reports = sizeof($reports);

	syslog_debug('We have ' . $syslog_reports . ' Reports in the database');

	if (cacti_sizeof($reports)) {
		foreach($reports as $syslog_report) {
			print '   Report: ' . $syslog_report['name'] . "\n";

			$base_start_time = $syslog_report['timepart'];
			$last_run_time   = $syslog_report['lastsent'];
			$time_span       = $syslog_report['timespan'];
			$seconds_offset  = read_config_option('cron_interval');

			$current_time = time();

			if (empty($last_run_time)) {
				$start = strtotime(date('Y-m-d 00:00', $current_time)) + $base_start_time;

				if ($current_time > $start) {
					/* if timer expired within a polling interval, then poll */
					if (($current_time - $seconds_offset) < $start) {
						$next_run_time = $start;
					} else {
						$next_run_time = $start+ 3600*24;
					}
				} else {
					$next_run_time = $start;
				}
			} else {
				$next_run_time = strtotime(date('Y-m-d 00:00', $last_run_time)) + $base_start_time + $time_span;
			}

			$time_till_next_run = $next_run_time - $current_time;

			if ($time_till_next_run < 0 || $forcer) {
				syslog_db_execute_prepared('UPDATE `' . $syslogdb_default . '`.`syslog_reports`
					SET lastsent = ?
					WHERE id = ?',
					array(time(), $syslog_report['id']));

				print '       Next Send: Now' . "\n";
				print "       Creating Report...\n";

				$sql     = '';
				$reptext = '';
				if ($syslog_report['type'] == 'messageb') {
					$sql = 'SELECT sl.*, sh.host
						FROM `' . $syslogdb_default . '`.`syslog` AS sl
						INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
						ON sl.host_id = sh.host_id
						WHERE message LIKE ' . db_qstr($syslog_report['message'] . '%');
				}

				if ($syslog_report['type'] == 'messagec') {
					$sql = 'SELECT sl.*, sh.host
						FROM `' . $syslogdb_default . '`.`syslog` AS sl
						INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
						ON sl.host_id = sh.host_id
						WHERE message LIKE ' . db_qstr('%' . $syslog_report['message'] . '%');
				}

				if ($syslog_report['type'] == 'messagee') {
					$sql = 'SELECT sl.*, sh.host
						FROM `' . $syslogdb_default . '`.`syslog` AS sl
						INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
						ON sl.host_id = sh.host_id
						WHERE message LIKE ' . db_qstr('%' . $syslog_report['message']);
				}

				if ($syslog_report['type'] == 'host') {
					$sql = 'SELECT sl.*, sh.host
						FROM `' . $syslogdb_default . '`.`syslog` AS sl
						INNER JOIN `' . $syslogdb_default . '`.`syslog_hosts` AS sh
						ON sl.host_id = sh.host_id
						WHERE sh.host = ' . db_qstr($syslog_report['message']);
				}

				if ($syslog_report['type'] == 'facility') {
					$sql = 'SELECT sl.*, sf.facility
						FROM `' . $syslogdb_default . '`.`syslog` AS sl
						INNER JOIN `' . $syslogdb_default . '`.`syslog_facilities` AS sf
						ON sl.facility_id = sf.facility_id
						WHERE sf.facility = ' . db_qstr($syslog_report['message']);
				}

				if ($syslog_report['type'] == 'program') {
					$sql = 'SELECT sl.*, sp.program
						FROM `' . $syslogdb_default . '`.`syslog` AS sl
						INNER JOIN `' . $syslogdb_default . '`.`syslog_programs` AS sp
						ON sl.program_id = sp.program_id
						WHERE sp.program = ' . db_qstr($syslog_report['message']);
				}

				if ($syslog_report['type'] == 'sql') {
					$sql = 'SELECT *
						FROM `' . $syslogdb_default . '`.`syslog`
						WHERE (' . $syslog_report['message'] . ')';
				}

				if ($sql != '') {
					$date2 = date('Y-m-d H:i:s', $current_time);
					$date1 = date('Y-m-d H:i:s', $current_time - $time_span);
					$sql  .= " AND logtime BETWEEN '". $date1 . "' AND '" . $date2 . "'";
					$sql  .= ' ORDER BY logtime DESC';
					$items = syslog_db_fetch_assoc($sql);

					syslog_debug('We have ' . db_affected_rows($syslog_cnn) . ' items for the Report');

					$classes = array('even', 'odd');

					if (cacti_sizeof($items)) {
						$i = 0;
						foreach($items as $item) {
							$class = $classes[$i % 2];
							$reptext .= '<tr class="' . $class . '"><td class="host">' . $item['host'] . '</td><td class="date">' . $item['logtime'] . '</td><td class="message">' . html_escape($item['message']) . "</td></tr>\n";
							$i++;
						}
					}

					if ($reptext != '') {
						$headtext  = "<html><head><style type='text/css'>\n";
						$headtext .= file_get_contents($config['base_path'] . '/plugins/syslog/syslog.css');
						$headtext .= "</style></head>\n";

						$headtext .= "<body>\n";

						$headtext .= "<h1>Cacti Syslog Report - " . $syslog_report['name'] . "</h1>\n";
						$headtext .= "<hr>\n";
						$headtext .= "<p>" . $syslog_report['body'] . "</p>";
						$headtext .= "<hr>\n";

						$headtext .= "<table>\n";
						$headtext .= "<tr><th>" . __('Host', 'syslog') . "</th><th>" . __('Date', 'syslog') . "</th><th>" . __('Message', 'syslog') . "</th></tr>\n";

						$headtext .= $reptext;

						$headtext .= "</table>\n";

						$headtext .= "</body>\n";
						$headtext .= "</html>\n";

						$smsalert  = '';

						syslog_sendemail($syslog_report['email'], $from, __esc('Event Report - %s', $syslog_report['name'], 'syslog'), $headtext, $smsalert);
					}
				}
			} else {
				print '       Next Send: ' . date('Y-m-d H:i:s', $next_run_time) . "\n";
			}
		}
	}

	syslog_debug('Finished processing Reports...');

	return $syslog_reports;
}

function syslog_process_log($start_time, $deleted, $incoming, $removed, $xferred, $alerts, $alarms, $reports) {
	global $database_default;

	/* record the end time */
	$end_time = microtime(true);

	cacti_log('SYSLOG STATS:' .
		' Time:'     . round($end_time-$start_time,2) .
		' Deletes:'  . $deleted  .
		' Incoming:' . $incoming .
		' Removes:'  . $removed  .
		' XFers:'    . $xferred  .
		' Alerts:'   . $alerts   .
		' Alarms:'   . $alarms   .
		' Reports:'  . $reports, true, 'SYSTEM'
	);

	set_config_option('syslog_stats',
		'time:' . round($end_time-$start_time,2) .
		' deletes:'  . $deleted  .
		' incoming:' . $incoming .
		' removes:'  . $removed  .
		' xfers:'    . $xferred  .
		' alerts:'   . $alerts   .
		' alarms:'   . $alarms   .
		' reports:'  . $reports
	);
}

function syslog_init_variables() {
	$syslog_retention = read_config_option('syslog_retention');
	$alert_retention  = read_config_option('syslog_alert_retention');

	if ($syslog_retention == '' or $syslog_retention < 0 or $syslog_retention > 365) {
		set_config_option('syslog_retention', '30');
	}

	if ($alert_retention == '' || $alert_retention < 0 || $alert_retention > 365) {
		set_config_option('syslog_alert_retention', '30');
	}

	if (substr(read_config_option('base_url'), 0, 4) != 'http') {
		if (read_config_option('force_https') == 'on') {
			$prefix = 'https://';
		} else {
			$prefix = 'http://';
		}

		set_config_option('base_url', $prefix . read_config_option('base_url'));
	}
}

function alert_setup_environment($alert, $a, $hostlist = array()) {
	global $severities, $syslog_levels, $syslog_facilities;

	putenv('ALERT_ALERTID='       . cacti_escapeshellarg($alert['id']));
	putenv('ALERT_NAME='          . cacti_escapeshellarg($alert['name']));
	putenv('ALERT_HOSTNAME='      . cacti_escapeshellarg($a['host']));
	putenv('ALERT_PRIORITY='      . cacti_escapeshellarg($syslog_levels[$a['priority_id']]));
	putenv('ALERT_FACILITY='      . cacti_escapeshellarg($syslog_facilities[$a['facility_id']]));
	putenv('ALERT_MESSAGE='       . cacti_escapeshellarg($a['message']));
	putenv('ALERT_SEVERITY='      . cacti_escapeshellarg($alert['severity']));
	putenv('ALERT_SEVERITY_TEXT=' . cacti_escapeshellarg($severities[$alert['severity']]));
	putenv('ALERT_HOSTLIST='      . cacti_escapeshellarg(implode(',', $hostlist)));
}

function alert_replace_variables($alert, $a) {
	global $severities, $syslog_levels, $syslog_facilities;

	$command = $alert['command'];

	$command = str_replace('<ALERTID>',  cacti_escapeshellarg($alert['id']), $command);
	$command = str_replace('<HOSTNAME>', cacti_escapeshellarg($a['host']), $command);
	$command = str_replace('<PRIORITY>', cacti_escapeshellarg($syslog_levels[$a['priority_id']]), $command);
	$command = str_replace('<FACILITY>', cacti_escapeshellarg($syslog_facilities[$a['facility_id']]), $command);
	$command = str_replace('<MESSAGE>',  cacti_escapeshellarg($a['message']), $command);
	$command = str_replace('<SEVERITY>', cacti_escapeshellarg($severities[$alert['severity']]), $command);

	return $command;
}
