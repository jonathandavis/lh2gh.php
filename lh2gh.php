#!/usr/bin/php
<?php
/*
Before running, you need to get authorization tokens

curl -u 'githubusername' -d '{"scopes":["repo"],"note":"For migration of Lighthouse tickets"}' https://api.github.com/authorizations

*/

date_default_timezone_set('America/New_York');

ini_set('display_errors', 0);
ini_set('error_log', 'error_log_lh2gh.txt');

define('LIGHTHOUSE_ACCOUNT','');	// Lighthouse account name
define('LIGHTHOUSE_TOKEN','');		// Lighthouse token (read token)
define('LIGHTHOUSE_PROJECT','');	// Lighthouse project id number

define('GITHUB_LOGIN','');			// GitHub account name
define('GITHUB_TOKEN','');			// GitHub token (from the command-line token generator above)
define('GITHUB_ORG','');			// GitHub organization (if present)
define('GITHUB_PROJECT','');		// GitHub project name

define('GITHUB_MAX_RETRIES', 5);	// Maximum number of tries before bailing

// List of Lighthouse tags to keep as GitHub labels
// All imported issues will be automatically tagged **lighthouse**
define('GITHUB_KEEPLABELS',array(
	'new','open','reopened','feedback','review','closed',
));

// Map of Lighthouse users to GitHub account names
define('GITHUB_ASSIGNEES', array(
	// LH		=>  GitHub account name
	'Name'		=> 'githubaccount',
));

define('STARTING_TICKET',1);

// Create the milestones first
GitHubAPI::milestones();

// Get all the ticket numbers
$tickets = LighthouseAPI::tickets();

// Line them up so that we can add place holder issues so our ticket numbers match
$tickets = GitHubAPI::aligntickets($tickets);

// Start at the beginning
reset($tickets);

// Fast-forward to the starting ticket
while (key($tickets) !== STARTING_TICKET) next($tickets);

while ( list($index,$id) = each($tickets) ) {
	if ( 0 === $index ) continue; // Skip 0th entry so ticket numbers line up with issue numbers
	if ( false === $id ) { // Handle empty placeholder issues
		GitHubAPI::placeholder($index);
		continue;
	}

	// Get the ticket details (everything)
	$ticket = LighthouseAPI::ticket($id);
	// Post the ticket as a GitHub issue
	$issuenum = GitHubAPI::post_ticket($ticket);

	if ( isset($ticket->versions) ) // If there are comments, add them to the new ticket
		GitHubAPI::comments($ticket->versions,$issuenum);

	if ( isset($ticket->attachments) ) // If there are attachements, add them as comments
		GitHubAPI::attachments($ticket->attachments,$issuenum);

	if ( 1 == $ticket->closed || 'review' == $ticket->state ) // Auto-close issues that are closed, or set for review
		GitHubAPI::closeticket($issuenum);

	sleep(1); // Give some breathing room so we don't exceed the GitHub API rate limit (5000 per hour)

}

echo "\n\n**Done**\n\n";
exit;

class GitHubAPI {

	const LOGIN = GITHUB_LOGIN;
	const TOKEN = GITHUB_TOKEN;
	const ORG = GITHUB_ORG;
	const PROJECT = GITHUB_PROJECT;
	const API = 'https://api.github.com/';

	private static $keeplabels = GITHUB_KEEPLABELS;

	private static $assignees = GITHUB_ASSIGNEES;

	public static function milestones () {
		echo "Collecting milestones from Lighthouse...\n";
		$milestones = LighthouseAPI::milestones();
		echo "Creating milestones";
		foreach ( $milestones as $data ) {
			$created = GitHubAPI::post_milestone($data->milestone);
			echo $created ? '.': '!';
		}
		echo "\n\n";
	}

	public static function post_labels () {

		foreach (self::$keeplabels as $entry) {
			$label = array(
				'name' => $entry,
				'color' => 'FFFFFF'
			);
			$response = self::post('labels',$label);
			if ( isset($response->url) ) echo '.';
			else echo '!';
		}

		return true;
	}

	public static function post_milestone ($milestone) {

		$open = isset($milestone->open_ticket_count) ? (int)$milestone->open_ticket_count : 0;
		$state = $open > 0 ? 'open' : 'closed';
		$new = array(
			'title' => $milestone->title,
			'state' => $state,
			'description' => $milestone->goals
		);

		if ( ! empty($milestone->due_on) )
			$new['due_on'] = $milestone->due_on;

		$response = self::post('milestones',$new);
		if ( ! isset($response->number) ) return false;

		Mapping()->milestones[ (int)$milestone->id ] = (int)$response->number;
		Mapping()->milenames[ (int)$milestone->id ] = $milestone->title;

		return true;
	}

	public static function post_ticket ($ticket) {

		if (false === $ticket) {
			return self::placeholder();
		}

		$created_at = DateTime::createFromFormat(DateTime::ISO8601,$ticket->created_at);
		$created = $created_at->format(DateTime::RFC850);

		$citation = "*Imported from Lighthouse* {$ticket->url}\n";
		$citation .= "Reported by **{$ticket->creator_name}** at **$created**\n\n";

		$issue = array(
			'title' => $ticket->title,
			'body' => self::body($citation . $ticket->original_body)
		);

		// Assignment
		$assigned = false;
		if ( isset($ticket->assigned_user_name) )
			$assigned = $ticket->assigned_user_name;
		if ( isset($assigned) && isset(self::$assignees[ $assigned ]) )
			$issue['assignee'] = self::$assignees[ $assigned ];

		// Milestone
		$milestones = Mapping()->milestones;
		if ( isset($milestones[ $ticket->milestone_id ]) )
			$issue['milestone'] = $milestones[ $ticket->milestone_id ];

		// Labels
		$labels = str_getcsv($ticket->tag,' ');
		$labels = array_intersect(self::$keeplabels,$labels);
		foreach ($labels as $label)
			$issue['labels'][] = $label;
		$issue['labels'][] = 'lighthouse'; // Adds lighthouse label
		$issue['labels'][] = $ticket->state; // Adds lighthouse ticket state as a label

		$response = self::post('issues',$issue);

		if ( ! $response || ! isset($response->title) || ! isset($response->number) ) { // If it fails, try and try again
			global $retries;
			if ( is_null($retries) ) $retries = 0;
			if ( $retries++ > GITHUB_MAX_RETRIES) {
				die("\n\nFAIL: Maximum retries reached. Could not submit $ticket->number to GitHub.\n\nIssue Data: " . @json_encode($issue) . "\n\nResponse: " . @json_encode($response) . "\n\nSorry! :(\n\n");
			}
			$issuenum = self::post_ticket($ticket);
			if ( $issuenum ) {
				$retries = null;
				return $issuenum;
			}
		}

		$ticketnum = $ticket->number;
		$issuenum = $response->number;

		echo "\n\n\"{$response->title}\" ($ticketnum > $issuenum)\n";

		return $issuenum;

	}

	public static function closeticket ($issuenum) {
		$response = self::patch('issues/' . $issuenum,array('state' => 'closed') );
	}

	public static function placeholder ($id) {
		$issue = array(
			'title' => "Removed Ticket #$id at Lighthouse",
			'body' => 'Nothing to see here. Move along.'
		);

		$response = self::post('issues',$issue);
		$issuenum = $response->number;

		$response = self::closeticket($issuenum);

		return $issuenum;
	}


	public static function comments ( $versions, $issuenum ) {
		echo "  Adding comments";

		foreach ($versions as $version) {
			if ( false !== strpos($version->body,'[bulk edit]') ) continue; // Skip bulk edits
			$changed = false;

			$created_at = DateTime::createFromFormat(DateTime::ISO8601,$version->created_at);
			$created = $created_at->format(DateTime::RFC850);

			$citation = "*Imported from Lighthouse*\n";
			$citation .= "Comment by **{$version->user_name}** at **$created**\n\n";

			if ( empty($version->body) && isset($version->diffable_attributes) ) {
				// Handle ticket meta changes
				$diffs = $version->diffable_attributes;
				$content = '';
				$changes = array(
					'tag' => '- **Tag changed from "%s" to "%s"**' . "\n",
					'state' => '- **State changed from "%s" to "%s"**' . "\n",
					'assigned_user' => '- **Issue assigned to "%s"**' . "\n",
					'title' => '- **Title changed from "%s" to "%s"**' . "\n",
					'milestone' => '- **Milestone changed from "%s" to "%s"**' . "\n"
				);

				$milestones = Mapping()->milenames;

				foreach ($changes as $key => $text) {
					if ( ! isset($diffs->$key) ) continue;
					$changed = true;
					$first = $diffs->$key;
					$second = '';
					switch ($key) {
						case 'tag': $second = $version->tag; break;
						case 'state': $second = $version->state; break;
						case 'assigned_user': $first = $version->assigned_user_name; break;
						case 'title': $second = $version->title; break;
						case 'milestone':
							$second = $version->milestone_id;
							if ( isset($milestones[ $first ]))
								$first = $milestones[ $first ];
							else $first = "Unknown";
							if ( isset($milestones[ $second ]))
								$second = $milestones[ $second ];
							else $second = 'Unknown';
							break;
					}
					$content = sprintf($text,$first,$second);
				}
			} else $content = $version->body;

			$comment = array(
				'body' => self::body($citation . $content)
			);

			$newcomment = self::post('issues/' . $issuenum . '/comments', $comment);

			echo '.';
		}
		echo "\n";
	}

	public static function attachments ( $attachments, $issuenum ) {
		echo "  Adding attachements";

		foreach ($attachments as $attachment) {
			$image = false;
			$file = false;
			if ( isset($attachment->image) ) {
				$image = true;
				$file = $attachment->image;
			} elseif ( isset($attachment->attachment) )
				$file = $attachment->attachment;

			if ( ! $file ) {
				echo '!';
				continue; // Invalid, skip to next one
			}

			$created_at = DateTime::createFromFormat(DateTime::ISO8601,$file->created_at);
			$created = $created_at->format(DateTime::RFC850);

			$citation = "*Imported from Lighthouse* [{$file->filename}]({$file->url})\n";
			$citation .= "**{$file->filename}** created at **$created**\n\n";

			if ($image) $contents = "![{$file->filename}]($file->url)";
			else  {
				$body = file_get_contents($file->url);
				if ( empty($body) ) continue;
				$language = self::language($file->filename);
				$contents = "```$language\n$body\n```\n";
			}

			$comment = array(
				'body' => self::body($citation . $contents)
			);

			$newfilecomment = self::post('issues/' . $issuenum . '/comments', $comment);

			echo '.';

		} // end foreach
		echo "\n";

	}

	public static function aligntickets ($tickets) {
		sort($tickets);
		$max = $tickets[count($tickets)-1];
		$ticketids = array_flip($tickets);

		$tickets = array_fill(0,$max+1,false);
		foreach ($tickets as $key => &$value) {
			if ( isset($ticketids[$key]) )
				$value = $key;
		}
		return $tickets;
	}

	private static function body ($string) {
		return substr($string, 0, 65533);
	}

	private static function language ($filename) {
		$extension = strtolower( substr( strrchr($filename,'.'), 1 ) );
		$languages = LinguistMap::$langs;
		if ( isset($languages[$extension]) ) return $languages[$extension];
		return '';
	}

	private static function post ( $endpoint, $data = false ) {

		$apiurl = $endpoint;
		if ( false === strpos($apiurl,self::API) )
			$apiurl = self::API . 'repos/' . self::ORG . '/' .self::PROJECT . '/' . $endpoint;

		$request = @json_encode($data);

		$connection = curl_init();
		curl_setopt( $connection, CURLOPT_URL, $apiurl );
		curl_setopt( $connection, CURLOPT_HTTPHEADER, array(
			'Authorization: token ' . self::TOKEN
		));
		if ( ! empty($data) ) {
			curl_setopt( $connection, CURLOPT_POST, true );
			curl_setopt( $connection, CURLOPT_POSTFIELDS, $request );
		}
		curl_setopt( $connection, CURLOPT_RETURNTRANSFER, true );

		$response = curl_exec( $connection );
		$status = curl_getinfo($connection);
		curl_close( $connection );

		$details = json_encode($status);

		// error_log("\n\nRequest: ($apiurl) $request\n\nResponse: $response\n\nConnection: $details");

		$json = json_decode($response);
		if ( isset($json->errors) ) echo "\n\nRequest: ($apiurl) $request\n\nResponse: $response\n\nConnection: $details\n\n";

		return $json;

	}

	private static function patch ( $endpoint, $data = false ) {

		$apiurl = $endpoint;
		if ( false === strpos($apiurl,self::API) )
			$apiurl = self::API . 'repos/' . self::ORG . '/' .self::PROJECT . '/' . $endpoint;

		$request = json_encode($data);

		$connection = curl_init();
		curl_setopt( $connection, CURLOPT_URL, $apiurl );
		curl_setopt( $connection, CURLOPT_CUSTOMREQUEST, 'PATCH' );
		curl_setopt( $connection, CURLOPT_HTTPHEADER, array(
			'Authorization: token ' . self::TOKEN
		));
		if ( ! empty($data) ) {
			curl_setopt( $connection, CURLOPT_POSTFIELDS, $request );
		}
		curl_setopt( $connection, CURLOPT_RETURNTRANSFER, true );

		$response = curl_exec( $connection );
		$status = curl_getinfo($connection);
		curl_close( $connection );

		$details = json_encode($status);

		// error_log("\n\nRequest: ($apiurl) $request\n\nResponse: $response\n\nConnection: $details");

		$json = json_decode($response);
		// if ( isset($json->errors) ) echo "\n\nRequest: ($apiurl) $request\n\nResponse: $response\n\nConnection: $details\n\n";

		return $json;

	}

}

class LighthouseAPI {

	const ACCOUNT = LIGHTHOUSE_ACCOUNT;
	const TOKEN = LIGHTHOUSE_TOKEN;
	const PROJECT = LIGHTHOUSE_PROJECT;
	const API = 'https://%s.lighthouseapp.com/';


	public static function milestones () {
		$results = self::send('projects/' . self::PROJECT . '/milestones.json', array('limit' => 1, 'page' => 1) );
		return $results->milestones;
	}

	public static function ticket ($id) {
		$results = self::send('projects/' . self::PROJECT . '/tickets' . '/' . $id . '.json'  );
		return $results->ticket;
	}

	public static function tickets () {
		$page = 1;
		$results = true;
		$tickets = array();
		echo 'Collecting tickets';
		while ( $results ) {
			$results = self::ticket_page($page++);
			if ( $results && is_array($results) ) $tickets = array_merge($tickets,$results);
			echo '.';
		}
		echo "\nFound " . count($tickets) . " to migrate.\n";
		return $tickets;
	}

	public static function assignees () {
		$page = 1;
		$results = true;
		$tickets = array();
		echo 'Collecting assignees';
		while ( $results ) {
			$results = self::ticket_page_assignees($page++);
			if ( $results ) $tickets = array_merge($tickets,$results);
			echo '.';
		}
		echo "\nFound " . count($tickets) . " to migrate.\n";
		$tickets = array_flip($tickets);
		$tickets = array_flip($tickets);
		return $tickets;
	}


	private static function ticket_page_assignees ( $page = 0 ) {
		$results = self::send('projects/' . self::PROJECT . '/tickets.json', array('page' => $page) );
		if ( ! isset($results->tickets) ) {
			echo json_encode($results);
		}
		$ticketpage = $results->tickets;
		$tickets = array();
		foreach ((array)$ticketpage as $data) {
			if ( empty($data) ) continue;

			$ticket = $data->ticket;
			if ( ! isset($ticket->assigned_user_name) ) continue;

			$tickets[] = $ticket->assigned_user_name;
		}
		if ( empty($tickets) ) return false;
		return $tickets;
	}

	private static function ticket_page ( $page = 0 ) {
		$results = self::send('projects/' . self::PROJECT . '/tickets.json', array('page' => $page) );
		if ( ! isset($results->tickets) || ! is_array($results->tickets) )
			return false;

		$ticketpage = $results->tickets;
		$tickets = array();
		foreach ((array)$ticketpage as $data) {
			if ( empty($data) ) continue;

			$ticket = $data->ticket;
			if ( ! isset($ticket->number) ) continue;

			$tickets[] = $ticket->number;
		}
		if ( empty($tickets) ) return false;
		return $tickets;
	}

	private static function send ( $endpoint, $query = array() ) {

		$project_id = self::PROJECT;
		$querystring = ! empty($query) ? '?' . http_build_query($query) : '';
		$apiurl = sprintf(self::API, self::ACCOUNT);

		$connection = curl_init();
		curl_setopt( $connection, CURLOPT_URL, "$apiurl$endpoint$querystring" );
		curl_setopt( $connection, CURLOPT_HTTPHEADER, array(
			'X-LighthouseToken: ' . self::TOKEN,
			'Content-type: application/xml'
		));
		curl_setopt( $connection, CURLOPT_RETURNTRANSFER, true );

		$response = curl_exec( $connection );
		curl_close( $connection );

		// error_log("Request: $apiurl$endpoint$querystring");
		// error_log("Response: $response");

		$json = json_decode($response);

		return $json;

	}
}

class LighthouseToGitHubMapping {

	public $milestones = array();
	public $milenames = array();

	private static $instance = false;

	private function __construct () {
	}

	public static function instance () {
		if ( ! self::$instance )
			self::$instance = new self;
		return self::$instance;
	}
}

function Mapping () {
	return LighthouseToGitHubMapping::instance();
}

class LinguistMap {

	public static $langs = array(
		'abap' => 'ABAP',
		'asp' => 'ASP',
		'as' => 'ActionScript',
		'adb' => 'Ada',
		'apacheconf' => 'ApacheConf',
		'cls' => 'Apex',
		'applescript' => 'AppleScript',
		'arc' => 'Arc',
		'ino' => 'Arduino',
		'asm' => 'Assembly',
		'aug' => 'Augeas',
		'ahk' => 'AutoHotkey',
		'awk' => 'Awk',
		'bat' => 'Batchfile',
		'befunge' => 'Befunge',
		'bmx' => 'BlitzMax',
		'boo' => 'Boo',
		'b' => 'Brainfuck',
		'bro' => 'Bro',
		'c' => 'C',
		'cs' => 'C#',
		'cpp' => 'C++',
		'chs' => 'Haskell',
		'clp' => 'CLIPS',
		'cmake' => 'CMake',
		'css' => 'CSS',
		'ceylon' => 'Ceylon',
		'ck' => 'ChucK',
		'clj' => 'Clojure',
		'coffee' => 'CoffeeScript',
		'lisp' => 'Lisp',
		'coq' => 'Coq',
		'feature' => 'Cucumber',
		'pyx' => 'Cython',
		'd' => 'D',
		'dot' => 'DOT',
		'darcspatch' => 'Patch',
		'dart' => 'Dart',
		'pas' => 'Delphi',
		'dasm16' => 'ASM',
		'diff' => 'Diff',
		'dylan' => 'Dylan',
		'epj' => 'Projects',
		'ecl' => 'Ecl',
		'e' => 'Eiffel',
		'ex' => 'Elixir',
		'elm' => 'Elm',
		'el' => 'Lisp',
		'erl' => 'Erlang',
		'fs' => 'F#',
		'f90' => 'FORTRAN',
		'factor' => 'Factor',
		'fy' => 'Fancy',
		'fth' => 'Forth',
		's' => 'GAS',
		'kid' => 'Genshi',
		'ebuild' => 'Ebuild',
		'eclass' => 'Eclass',
		'po' => 'Catalog',
		'go' => 'Go',
		'gs' => 'Gosu',
		'man' => 'Groff',
		'groovy' => 'Groovy',
		'gsp' => 'Pages',
		'html' => 'HTML',
		'erb' => 'ERB',
		'phtml' => 'PHP',
		'http' => 'HTTP',
		'haml' => 'Haml',
		'handlebars' => 'Handlebars',
		'hs' => 'Haskell',
		'hx' => 'Haxe',
		'ini' => 'INI',
		'irclog' => 'log',
		'io' => 'Io',
		'ik' => 'Ioke',
		'json' => 'JSON',
		'java' => 'Java',
		'jsp' => 'Pages',
		'js' => 'JavaScript',
		'kt' => 'Kotlin',
		'll' => 'LLVM',
		'lasso' => 'Lasso',
		'less' => 'Less',
		'ly' => 'LilyPond',
		'litcoffee' => 'CoffeeScript',
		'lhs' => 'Haskell',
		'ls' => 'LiveScript',
		'lgt' => 'Logtalk',
		'lua' => 'Lua',
		'm' => 'M',
		'md' => 'Markdown',
		'matlab' => 'Matlab',
		'minid' => 'Max',
		'moo' => 'Moocode',
		'moon' => 'MoonScript',
		'myt' => 'Myghty',
		'nsi' => 'NSIS',
		'n' => 'Nemerle',
		'nginxconf' => 'Nginx',
		'nim' => 'Nimrod',
		'm' => 'C',
		'opa' => 'Opa',
		'cl' => 'OpenCL',
		'php' => 'PHP',
		'parrot' => 'filenames',
		'pir' => 'Representation',
		'pasm' => 'Assembly',
		'pl' => 'Perl',
		'ps1' => 'PowerShell',
		'pde' => 'Processing',
		'prolog' => 'Prolog',
		'pd' => 'filenames',
		'py' => 'Python',
		'pytb' => 'filenames',
		'r' => 'R',
		'rhtml' => 'RHTML',
		'rkt' => 'Racket',
		'raw' => 'data',
		'rebol' => 'Rebol',
		'rg' => 'Rouge',
		'rb' => 'Ruby',
		'rs' => 'filenames',
		'scss' => 'SCSS',
		'sql' => 'SQL',
		'sage' => 'Sage',
		'sass' => 'Sass',
		'scala' => 'Scala',
		'scm' => 'Scheme',
		'self' => 'Self',
		'sh' => 'Shell',
		'st' => 'Smalltalk',
		'tpl' => 'Smarty',
		'sml' => 'ML',
		'sc' => 'SuperCollider',
		'toml' => 'TOML',
		'txl' => 'TXL',
		'tcl' => 'Tcl',
		'tcsh' => 'Tcsh',
		'tex' => 'TeX',
		'tea' => 'Tea',
		'textile' => 'Textile',
		't' => 'Turing',
		'ts' => 'TypeScript',
		'vhdl' => 'VHDL',
		'vala' => 'Vala',
		'vb' =>  'Visual Basic',
		'xslt' => 'XSLT',
		'xtend' => 'Xtend',
		'yml' => 'YAML',
		'fish' => 'fish',
		'mu' => 'mupad',
		'ooc' => 'ooc',
		'rst' => 'reStructuredText',
	);
}