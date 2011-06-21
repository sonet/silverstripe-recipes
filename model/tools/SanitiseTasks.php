<?php

/**
Copyright (c) 2007-2011, SilverStripe Limited - www.silverstripe.com
All rights reserved.

Redistribution and use in source and binary forms, with or without modification, are permitted provided that the following conditions are met:

    * Redistributions of source code must retain the above copyright notice, this list of conditions and the following disclaimer.
    * Redistributions in binary form must reproduce the above copyright notice, this list of conditions and the following disclaimer in the
      documentation and/or other materials provided with the distribution.
    * Neither the name of SilverStripe nor the names of its contributors may be used to endorse or promote products derived from this software
      without specific prior written permission.

THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT OWNER OR CONTRIBUTORS BE
LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE
GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT,
STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY
OF SUCH DAMAGE.
 */

/**
 * Two tasks to clean up a database after an extended content loading session prior to going live
 *
 * - SanitiseTasks_CleanHistory cleans all but the most recent version of pages
 *
 * - SanitiseTasks_CleanNonNavigatable removes any pages that aren't navigatable to any more (because their parents were
 * deleted somehow - probably by the CleanHistory task)
 *
 */


/**
 * After content loading for a new site, the history of the SiteTree can become too muddled to be useful.
 *
 * This task will remove all but the latest live version of every Page, and reset the version counter to 1
 *
 * When a Page has no live version, but does have a staged version, this script will do one of three things, depending
 * on argument
 *
 *   - Remove all versions except the most recent staged version, reseting the version counter to 1 but leaving the Page
 *     as staged only, not live
 *
 *   - As above, but also publish that most recent staged version
 *
 *   - Remove all versions, deleting the Page completely
 *
 * For Pages that have no stage or live version (i.e. fully deleted pages) this script will always remove all old versions
 *
 * This task is designed to work with the standard Versioned decorator, and optionally the subsites module.
 *
 * WARNING: This code uses direct database access. Extensions or changes to core could mean this script just breaks
 * your database or damages referential integrity. Ensure you backup your data before running. Assume this script
 * will break your data
 *
 * WARNING: If you choose stagemode=delete, you could end up with orphaned pages (pages with no parent). Make sure you
 * run SanitiseTasks_CleanNonNavigatable afterwards
 *
 * WARNING: Make sure your manifest & database are up to date. This will remove all Pages of unknown classes, regardless
 * of published state.
 *
 * Call like so:
 *
 *   sapphire/sake dev/tasks/SanitiseTasks_CleanHistory stagemode=XXX
 *
 * XXX can be one of "delete", "keep" or "publish" to say way happens to Pages with a staged version but no live version
 *
 * You can also call with quiet=1, like
 *
 *   sapphire/sake dev/tasks/SanitiseTasks_CleanHistory quiet=1 stagemode=XXX
 *
 */
class SanitiseTasks_CleanHistory extends BuildTask {
	static $recordsPerRequest = 50;

	protected $description = 'Removes all but the latest live versions of Pages, and either removes, publishes or keeps Pages which have no live version';
	
	public function run($request) {
		increase_time_limit_to();

		$allowedStageModes = array('delete', 'keep', 'publish');

		if (!isset($_GET['stagemode']) || !($stagemode = $_GET['stagemode']) || !in_array($stagemode, $allowedStageModes)) {
			echo "Must pass stage mode to indicate what to do with pages with no published version - ". implode(',', $allowedStageModes);
			die;
		}

		if (isset($_GET['ids'])) {
			$this->runOn(explode(',', $_GET['ids']), $stagemode);
		}
		else {
			if (!isset($_GET['acknowledged'])) {
				echo "This task is unsafe. Backup your database first, and make sure all data is good afterwards\n";
				if ($stagemode == 'delete') {
					echo "Task PERMANENTLY DELETES all Pages except the most recent live version\n";
					echo "Task BREAKS page tree. Make sure you run CleanNonNavigatable task afterwards\n";
				}
				elseif ($stagemode == 'publish') {
					echo "If there is no most recent live version of a page, but there is a staged version, that version will be PUBLISHED.\n";
					echo "Task then PERMANENTLY DELETES all Pages except the most recent live version.";
				}
				else {
					echo "Task then PERMANENTLY DELETES all Pages except the most recent live version, or if there is no live version the most recent staged version\n";
				}
				echo "Add acknowledged=true to the command and re-run\n";
				die;
			}
			
			$idQuery = new SQLQuery('DISTINCT RecordID', 'SiteTree_versions', '', 'RecordID ASC');
			$ids = $idQuery->execute()->column();
			$total = count($ids);

			echo "Total: $total\n";
			echo "May god have mercy on your data\n";

			$script = sprintf('%s%ssapphire%scli-script.php', BASE_PATH, DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);
			$class = get_class($this);

			for ($offset = 0; $offset < $total; $offset += $this->stat('recordsPerRequest')) {
				echo "$offset..";

				$sectionedids = implode(',', array_slice($ids, $offset, $this->stat('recordsPerRequest')));
				$res = `php $script dev/tasks/$class stagemode=$stagemode ids=$sectionedids`;
				
				if (!isset($_GET['quiet'])) echo "\n  ".preg_replace('/\r\n|\n/', '$0  ', $res)."\n";
			}

			echo "Final cleanup\n";

			DB::query("DELETE FROM SiteTree WHERE ID NOT IN (SELECT RecordID FROM SiteTree_versions)");
			DB::query("DELETE FROM SiteTree_Live WHERE ID NOT IN (SELECT RecordID FROM SiteTree_versions)");

			foreach (ClassInfo::dataClassesFor('SiteTree') as $table) {
				if ($table == 'SiteTree') continue;

				DB::query("DELETE FROM {$table} WHERE ID NOT IN (SELECT ID FROM SiteTree)");
				DB::query("DELETE FROM {$table}_Live WHERE ID NOT IN (SELECT ID FROM SiteTree_Live)");
			}
			
			echo "Done\n";
		}
	}

	protected function runOn($recordIDs, $stagemode) {
		$dataObjectClasses = ClassInfo::subclassesFor('SiteTree');
		$siteTreeTables = ClassInfo::dataClassesFor('SiteTree');

		foreach ($recordIDs as $recordID) {
			$source = 'live';
			$liveQuery = new SQLQuery('Version, ClassName', 'SiteTree_Live', "ID = $recordID");
			$data = $liveQuery->execute()->first();

			if (!$data && $stagemode != 'delete') {
				$source = 'stage';
				$stageQuery = new SQLQuery('Version, ClassName', 'SiteTree', "ID = $recordID");
				$data = $stageQuery->execute()->first();
			}

			$class = $data ? $data['ClassName'] : null;
			$version = $data ? $data['Version'] : null;

			if (!$class || !in_array($class, $dataObjectClasses)) {
				echo "Deleting ID $recordID\n";
				if ($class) echo "Exists, but of class $class which isn't a valid DataObject class any more\n";

				foreach ($siteTreeTables as $table) {
					DB::query("DELETE FROM {$table} WHERE ID = $recordID");
					DB::query("DELETE FROM {$table}_Live WHERE ID = $recordID");
					DB::query("DELETE FROM {$table}_versions WHERE RecordID = $recordID");
				}
			}
			else {
				echo "Compressing ID $recordID class $class to version $version\n";

				foreach ($siteTreeTables as $table) {
					DB::query("DELETE FROM {$table}_versions WHERE RecordID = $recordID AND Version <> $version");
					DB::query("UPDATE {$table}_versions SET Version=1 WHERE RecordID = $recordID");

					if ($source == 'stage' && $stagemode != 'publish') {
						DB::query("DELETE FROM {$table}_Live WHERE ID = $recordID");
					}
					else {
						if ($source == 'live') { $from = "{$table}_Live"; $to = $table; }
						else                   { $from = $table; $to = "{$table}_Live"; }

						$dataQuery = new SQLQuery('*', $from, "ID = $recordID");
						$res = $dataQuery->execute()->first();

						if ($res) {
							$updates = array();

							foreach ($res as $key => $value) {
								if ($key == 'ID') continue;

								if ($value === null) $updates[] = "$to.$key=NULL";
								else if (is_numeric($value)) $updates[] = "$to.$key=$value";
								else $updates[] = "$to.$key='".Convert::raw2sql($value)."'";
							}

							if ($updates) DB::query("UPDATE $to SET ".implode(', ', $updates)." WHERE ID = $recordID");
						}
					}
				}

				DB::query("UPDATE SiteTree SET Version=1 WHERE ID = $recordID");
				DB::query("UPDATE SiteTree_Live SET Version=1 WHERE ID = $recordID");
				if ($source == 'live' || $stagemode == 'publish') DB::query("UPDATE SiteTree_versions SET WasPublished=1 WHERE ID = $recordID");
			}
		}
	}

}

/**
 * If you ran SanitiseTasks_CleanHistory with stagemode=delete, you might have removed "staged only" parents of published
 * children, orphaning those children
 *
 * This task will find all pages that can't be navigated through to from the site root and remove them
 *
 * This task is designed to work with the standard Versioned decorator, and optionally the subsites module.
 */
class  SanitiseTasks_CleanNonNavigatable extends BuildTask {
	static $recordsPerRequest = 50;

	public function run($request) {
		increase_time_limit_to();

		if (isset($_GET['ids'])) {
			$this->runOn(explode(',', $_GET['ids']));
		}
		else {
			$query = new SQLQuery('ID', 'SiteTree_Live');
			$ids = $query->execute()->column();
			$total = count($ids);
			
			echo "Total: $total\n";

			$script = sprintf('%s%ssapphire%scli-script.php', BASE_PATH, DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);
			$class = get_class($this);

			for ($offset = 0; $offset < $total; $offset += $this->stat('recordsPerRequest')) {
				echo "$offset..";

				$sectionedids = implode(',', array_slice($ids, $offset, $this->stat('recordsPerRequest')));
				$res = `php $script dev/tasks/$class ids=$sectionedids`;
				
				if (!isset($_GET['quiet'])) echo "\n  ".preg_replace('/\r\n|\n/', '$0  ', $res)."\n";
			}

			echo "Done\n";
		}
	}

	protected function runOn($ids) {
		$siteTreeTables = ClassInfo::dataClassesFor('SiteTree');

		if (class_exists('Subsite')) Subsite::$disable_subsite_filter = true;
		$pages = DataObject::get('SiteTree', 'SiteTree.ID IN ('.implode(',', $ids).')');

		foreach ($pages as $page) {
			$parent = $page;
			while ($parent && $parent->ID != 0 && $parent->ParentID != 0 && $parent->ParentID != null) $parent = $parent->Parent();

			if (!$parent || $parent->ID == 0) {
				// We ran out of parents and didn't hit '0', so this page is disconnected
				echo "Removing unreachable page, ID: {$page->ID}, Class: {$page->ClassName}, Title: {$page->Title}\n";

				foreach ($siteTreeTables as $table) {
					DB::query("DELETE FROM {$table} WHERE ID = {$page->ID}");
					DB::query("DELETE FROM {$table}_Live WHERE ID = {$page->ID}");
					DB::query("DELETE FROM {$table}_versions WHERE RecordID = {$page->ID}");
				}
			}
		}
	}

}
