<?php

// Were command-line parameters provided?  (Note, $argc always starts with 1 item)
if ( $argc < 3 ) {
	echo "LAIKA: ERROR, the required command-line parameters were not supplied.\n\n";
	echo "php ./fetch.php [pack-member] [date]\n\n";
	echo "pack-member = The domain to fetch data for. Laika will look for a folder in the /pack-members/ folder with this name.\n\n";
	echo "date = A date code like 2016-04-01 to tell Laika to fetch data for April, 2016. Be sure to supply the day of the month, even though it isn't used.\n";
	exit;
}

$CURRENT_HOST = $argv[1];
$REPORT_DATE  = $argv[2];

$TENANT_URL   = "pack-members/";		// If this changes, also change it in [header.php]

$COLLAR       = $TENANT_URL . $CURRENT_HOST . '/collar.php';

// $DEBUG = TRUE // Force debugging by uncommenting this line

// --- ERROR CHECKING ----------------------------------------------------------

// Do the tenant files exist?
if ( !file_exists($COLLAR) ) {
	echo "LAIKA: ERROR, a collar could not be found at: " . $COLLAR . "\n";
	exit;
}

// Is the date parameter properly formed?
if ( !validateDate($REPORT_DATE) ) {
	echo "LAIKA: ERROR, the date parameter appears to be malformed: " . $REPORT_DATE . "\n";
	exit;
}

// --- LOAD COMMON ITEMS -------------------------------------------------------

include($COLLAR);
include('includes/common.php');

// --- LOAD FUNCTIONS ----------------------------------------------------------

// Use this array to store files that will be loaded
// That way we can debug on it later (look in footer.php)
$loadFile = array();

// String translations
$loadFile[] = 'translations/' . $LANGUAGE . '.php';

// Medoo (database interaction)
$loadFile[] = 'plugins/medoo-1.0.2.php';

// GAPI (to fetch data from Google Analytics)
$loadFile[] = 'plugins/gapi.class.php';

// Now, actually load all of those files
foreach ( $loadFile as $file ) {
	require $file;
}

// --- START -------------------------------------------------------------------

// Write branding header
writeText("LAIKA v" . $VERSION, TRUE);

// Create a human-readable date format that will look better in debug output
$REPORT_DATE_NICE = date('F Y', strtotime($REPORT_DATE));

// Prepare the GAPI variables
$gapi_profile_id = NULL;
$gapi_date_start = NULL;
$gapi_date_end   = NULL;

// --- DATABASE CONNECTION -----------------------------------------------------

try {

	$DATABASE = new medoo([
		// required
		'database_type' => 'mysql',
		'database_name' => $DB_NAME,
		'server' => 'localhost',
		'username' => $DB_USER,
		'password' => $DB_PASS,
		'charset' => 'utf8',
		'port' => 3306,

		// [optional] Table prefix
		// 'prefix' => 'PREFIX_',

		// driver_option for connection, read more from http://www.php.net/manual/en/pdo.setattribute.php
		'option' => [
			PDO::ATTR_CASE => PDO::CASE_NATURAL
		]
	]);

} catch ( Exception $e ) {

	writeText($GLOBALS['txtErrorDatabaseConnectionFailed'] . " " . $e->getMessage());
	exit;

}

// --- CHECK FOR ERRORS --------------------------------------------------------

// Get the list of all sites that Laika will be fetching data for (that don't have [ignore = 1])
$sites = $DATABASE->select($TBL_SITES , "*", [
		"ignore[!]" => 1
	]);

// DEBUG
// Only look up certain sites (for faster testing)
/*
$sites = $DATABASE->select( $TBL_SITES , "*", [
		"id" => 1
	] );
*/

// Check for error: Is the requested report date in the future?
if ( strtotime($REPORT_DATE) > time() ) {
	$txtError  = $GLOBALS['txtErrorFutureDataA'];
	$txtError .= " " . $REPORT_DATE . " ";
	$txtError .= $GLOBALS['txtErrorFutureDataB'];

	writeText($txtError);
	exit;
}

// Check for error: Are there sites actually defined?
if ( empty($sites) ) {
	writeText($GLOBALS['txtErrorNoSitesDefined'], TRUE);
	exit;
}

// Check for error: Is there already data for the month that we are fetching for?
// If so, quit, because we don't want to overwrite anything or mess up the DB.
$existingData = $DATABASE->select( $TBL_DATA, "*", [
		"date" => $REPORT_DATE
	]);

if ( !empty($existingData) ) {

	$txtError  = $GLOBALS['txtErrorDataAlreadyExistsA'];
	$txtError .= " " . $CURRENT_HOST . " ";
	$txtError .= $GLOBALS['txtErrorDataAlreadyExistsB'];
	$txtError .= " " . $REPORT_DATE . " ";
	$txtError .= $GLOBALS['txtErrorDataAlreadyExistsC'];

	writeText($txtError);
	exit;
}

// --- START THE REPORTING GATHERING -------------------------------------------

// Output the start time
$timeStart = new DateTime();
$txtTimeStart = $timeStart->format($GLOBALS['formatFullDate']);
writeText($GLOBALS['txtStartedAt'] . " " . $txtTimeStart , TRUE);

// Perform the operations iteratively for each site
foreach ( $sites as $thisSite ) {

	$txtStart  = $GLOBALS['txtStartFetchOperation'];
	$txtStart .= " " . $thisSite["name"];
	$txtStart .= " (site " . $thisSite["id"] . ")";
	$txtStart .= " for " . $REPORT_DATE_NICE;
	$txtStart .= " on pack member " . $CURRENT_HOST;

	// writeOutput("Laika is preparing to fetch data for " . $thisSite["name"] . " (site " . $thisSite["id"] . ") for " . $REPORT_DATE_NICE, TRUE);
	writeText($txtStart, TRUE);

	// Look up the ID numbers supplied by the sites/additional_metrics field
	$additional_metrics = $DATABASE->select( $TBL_SITES, "additional_metrics", [
		"id" => $thisSite["id"]
		]);

	// DEBUG
	/*
	writeOutput("Here are the additional metrics as defined by the current site:", TRUE);
	writeOutput(print_r( explode(",", $additional_metrics[0]) ), TRUE);
	*/

	// Get the set of global metrics and those defined by the current site
	$operations = $DATABASE->select($TBL_METRICS, "*", [
		"OR" => [
			"is_global" => 1,
			"id" => explode(",", $additional_metrics[0])
		]]);

	// DEBUG
	// Comment out the block above and use this one to only perform certain operations (for faster testing)
	/*
	$operations = $DATABASE->select( $TBL_METRICS , "*", [
		"id" => [1, 2, 3, 4, 13, 14]
		// "id" => [43, 44, 45, 46, 47, 48, 49, 50, 51, 52]
		]);
	*/

	// DEBUG
	$theseOperations = "  - ";
	writeOutput($GLOBALS['txtOperationsToPerform']);
	foreach ( $operations as $thisOperation ) {
		$theseOperations .= $thisOperation["id"] . ",";
	}
	writeOutput(rtrim($theseOperations,","), TRUE);

	// --- GOOGLE ANALYTICS CONNECTION -----------------------------------------

	// Look up the site's GAN view ID
	$gapi_profile_id = $thisSite["gan_view_id"];

	$gapi_date_start = date('Y-m-01', strtotime($REPORT_DATE));
	$gapi_date_end   = date('Y-m-t', strtotime($REPORT_DATE));

	$ga = new gapi( $CREDENTIAL_NAME, $TENANT_URL . $CREDENTIAL_FILE );

	// -------------------------------------------------------------------------

	// For each operation
	foreach ( $operations as $thisOperation ) {

		$skip = FALSE;

		writeOutput($GLOBALS['txtOperationNumber'] . $thisOperation["id"]);

		// Define reasons why an operation should be skipped:
		// - metrics/operation field is empty

		// Skip if the metrics/operation field is empty
		if ( empty( $thisOperation["operation"] ) ) {

			writeOutput("  - " . $GLOBALS['txtOperationSkipped'], TRUE);
			$skip = TRUE;

		}

		// Should this operation be skipped for any reason?
		if ( !$skip ) {

			// Proceed with the operation
			writeOutput("  - " . $thisOperation["operation"]);

			$thisValue = eval( "return " . $thisOperation["operation"] );

			writeValue( $thisSite["id"], $REPORT_DATE, $thisOperation["id"], $thisValue );

			writeOutput("  - Result = " . $thisValue, TRUE);
		}

	}

	writeOutput("separator");

}

// Output the end time and calculate execution duration
$timeEnd = new DateTime();
$txtTimeEnd = $timeEnd->format($GLOBALS['formatFullDate']);
$timeDuration = $timeEnd->diff($timeStart,TRUE);
$txtDuration = $timeDuration->format($GLOBALS['formatMinSec']);

$txtComputationTime  = $GLOBALS['txtFinishedAtA'];
$txtComputationTime .= " ";
$txtComputationTime .= $txtTimeEnd;
$txtComputationTime .= " ";
$txtComputationTime .= $GLOBALS['txtFinishedAtB'];
$txtComputationTime .= " ";
$txtComputationTime .= $txtDuration;

writeText($txtComputationTime, TRUE);

writeOutput("separator");

writeFinalReport();

writeOutput("separator");

// --- FUNCTIONS ---------------------------------------------------------------

function fetchData($name, $dimensions, $metrics, $filter = NULL) {

	global $ga;
	global $gapi_profile_id;
	global $gapi_date_start;
	global $gapi_date_end;
	global $FETCH_THROTTLE_DELAY;

	// requestReportData( $report_id, $dimensions, $metrics, $sort_metric=NULL, $filter=NULL, $start_date=NULL, $end_date=NULL, $start_index=1, $max_results=30 );
	// Specify 0 results since we won't be drilling down into this data and we want to speed things up
	$ga->requestReportData($gapi_profile_id, $dimensions, $metrics, NULL, $filter, $gapi_date_start, $gapi_date_end, 1, 0);

	// Observe rate quota by sleeping after each GAPI request
	sleep( $FETCH_THROTTLE_DELAY );

	return $ga->$name();
}

function fetchDataCountryVisits( $countryName ) {

	global $ga;
	global $gapi_profile_id;
	global $gapi_date_start;
	global $gapi_date_end;
	global $FETCH_THROTTLE_DELAY;

	// $strFilter must be a string like "country == United States"
	$strFilter = "country == " . $countryName;

	$ga->requestReportData($gapi_profile_id, 'country', 'sessions', NULL, $strFilter, $gapi_date_start, $gapi_date_end);

	// Observe rate quota by sleeping after each GAPI request
	sleep( $FETCH_THROTTLE_DELAY );

	$thisCountryVisits = $ga->getSessions();

	return $thisCountryVisits;

}

function fetchGoalData() {

	global $ga;
	global $gapi_profile_id;
	global $gapi_date_start;
	global $gapi_date_end;
	global $FETCH_THROTTLE_DELAY;

	global $DATABASE;
	global $TBL_GOALS;
	global $REPORT_DATE;
	global $thisSite;
	global $thisOperation;

	// Look up the GAN profile and goal number for this site/item
	$thisGoal = $DATABASE->select( $TBL_GOALS, "*", [
			"AND" => [
				"report_item" => $thisOperation["id"],
				"site" => $thisSite["id"]
				]
		]);

	// Return 0 if a site doesn't track a particular goal
	if ( empty( $thisGoal ) ) {

		// DEBUG
		writeOutput("    - " . $GLOBALS['txtGoalNoData']);
		$value = 0;

	} else {

		$goalOperationA = "goal" . $thisGoal[0]["gan_goal_id"] . "Completions";		// goal9Completions
		$goalOperationB = "getGoal" . $thisGoal[0]["gan_goal_id"] . "Completions";		// getGoal9Completions

		// Run the functions using the above variables
		$ga->requestReportData( $thisGoal[0]["gan_profile"], 'country', $goalOperationA, NULL, NULL, $gapi_date_start, $gapi_date_end, 1, 0);
		$value = $ga->$goalOperationB();

		// DEBUG
		/*
		writeOutput("GA result = [" . $value . "]");
		writeOutput("separator");
		*/

		// Observe rate quota by sleeping after each GAPI request
		sleep( $FETCH_THROTTLE_DELAY );

		$txt  = "  - " . $GLOBALS['txtSite'];
		$txt .= $thisSite["id"];
		$txt .= ", " . $GLOBALS['txtGANProfileID'];
		$txt .= " = " . $thisGoal[0]["gan_profile"];
		$txt .= ", " . $GLOBALS['txtGoalID'];
		$txt .= " = " . $thisGoal[0]["gan_goal_id"];

		// writeOutput("    - Site = " . $thisSite["id"] . ", GAN Profile ID = " . $thisGoal[0]["gan_profile"] . ", Goal ID = " . $thisGoal[0]["gan_goal_id"]);

		writeOutput( $txt );

	}

	return $value;

}

function dePercent($value) {
	return $value / 100;
}

function writeValue($site, $date, $item, $value) {

	global $DATABASE;
	global $TBL_DATA;

	// CREATE THIS FUNCTION SO THAT LAIKA CAN WRITE DATA TO THE DATABASE

	$writeThis = $DATABASE->insert($TBL_DATA, [
			"date"    => $date,
			"site"    => $site,
			"data_id" => $item,
			"value"   => $value
		]);

}

function getValue($id) {

	global $DATABASE;
	global $TBL_DATA;
	global $REPORT_DATE;
	global $thisSite;
	global $thisOperation;

	$value = $DATABASE->select($TBL_DATA, "value", [
		"AND" => [
			"data_id" => $id,
			"site"    => $thisSite["id"],
			"date"    => $REPORT_DATE
		]
	]);

	// DEBUG
	// writeOutput(print_r( $value ));
	// Will this always be value[0] ?

	// If no data is returned, exit with an error

	if ( empty($value) ) {

		$txt  = $GLOBALS['txtErrorGetValueFailedA'];
		$txt .= $id;
		$txt .= $GLOBALS['txtErrorGetValueFailedB'];
		$txt .= " " . $thisOperation["id"] . " ";
		$txt .= $GLOBALS['txtErrorGetValueFailedC'];

		writeText($txt);
		exit;
	}

	writeOutput("    - getValue(" . $id . ") = [" . $value[0] . "]");

	return $value[0];

}

function writeOutput($message, $doubleSpace = NULL) {

	// Writes a message to the screen if $DEBUG is TRUE
	global $DEBUG;

	if ( $DEBUG ) {
		writeText( $message, $doubleSpace );
	}

}

function writeText($message, $doubleSpace = NULL) {

	// Writes a message to the screen regardless of $DEBUG value
	// Use writeText instead of writeOutput to display error messages

	if ( $message == "separator" ) {
		echo "\n--------------------------------------------------------------------------------\n\n";
	} else {
		echo $message . "\n";
	}

	if ( $doubleSpace ) {
		echo "\n";
	}

}

function writeFinalReport() {

	global $DATABASE;
	global $TBL_DATA;
	global $REPORT_DATE;

	// Write a final report in CSV format showing all of the data that was just added
	$finalReport = $DATABASE->select($TBL_DATA, "*", [
			"date" => $REPORT_DATE
		]);

	writeText($GLOBALS['txtFinalReport'], TRUE);

	writeText("id,date,site,data_id,value");

	foreach ( $finalReport as $item ) {

		$txt  = $item["id"];
		$txt .= ",";
		$txt .= $item["date"];
		$txt .= ",";
		$txt .= $item["site"];
		$txt .= ",";
		$txt .= $item["data_id"];
		$txt .= ",";
		$txt .= $item["value"];

		writeText($txt);
	}

}

function validateDate($date) {
	$d = DateTime::createFromFormat('Y-m-d', $date);
	return $d && $d->format('Y-m-d') === $date;
}

?>