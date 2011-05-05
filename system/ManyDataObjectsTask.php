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
 * Often you want to write a task that operates on many DataObjects. Unfortunately if you just do a naive
 *
 *   foreach(DataObject::get('SiteTree') as $page) { .... }
 *
 * you'll often have problems with memory on large sites, since each page object is loaded into the same
 * process, and PHP's garbage collection won't clean up sufficiently.
 *
 * The best option is to split up the task into smaller sets, which are then called in a subprocess. When
 * the subprocess ends, all it's memory is discarded, so you never run out of memory.
 *
 * Here's an easy class to do just that - just extend this class, set the statics, and provide your own implementation
 * of the runOn method
 *
 * This pattern does assume you have the php binary in your path, and won't work as is if you're deleting
 * or adding the records you're looping over, although a modified version of this pattern will deal with that
 * situation too.
 */
abstract class ManyDataObjectsTask extends BuildTask {
	static $dataObjectType = 'SiteTree';
	static $dataObjectsPerRequest = 50;

	public function run($request) {
		increase_time_limit_to();

		if (isset($_GET['offset'])) {
			$this->runSubprocess($_GET['offset']);
		}
		else {
			exec('php --version', $output, $return_val);
			if ($return_val !== 0) {
				throw new Exception('Could not find php executable. Please put it in the system PATH environment variable');
			}

			$type = $this->stat('dataObjectType');
			$base = ClassInfo::baseDataClass($type);

			$query = new SQLQuery("COUNT(\"$base\".\"ID\")", $type);
			singleton($type)->extend('augmentSQL', $query);

			$total = $query->execute()->value();

			echo "Total: $total\n";

			$script = sprintf('%s%ssapphire%scli-script.php', BASE_PATH, DIRECTORY_SEPARATOR, DIRECTORY_SEPARATOR);
			$class = get_class($this);

			for ($offset = 0; $offset < $total; $offset += $this->stat('dataObjectsPerRequest')) {
				echo "$offset..";
				$res = `php $script dev/tasks/$class offset=$offset`;

				if (isset($_GET['verbose'])) echo "\n  ".preg_replace('/\r\n|\n/', '$0  ', $res)."\n";
			}

			echo "Done\n";
		}
	}

	protected function runSubprocess($offset) {
		$type = $this->stat('dataObjectType');
		$dataObjects = DataObject::get($type, '', '', '', array('limit' => $this->stat('dataObjectPerRequest'), 'start' => $offset));

		foreach ($dataObjects as $dataObject) $this->runOn($dataObject);
	}

	abstract protected function runOn($dataObjec); // Override this

}