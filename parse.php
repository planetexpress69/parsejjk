<?php
header("Content-Type:text/plain");

/*****************************************************************************
 * parse.php
 * a tiny handcrafted (aka hacked) parser to fill a given mysql table with
 * data coming from JJK
 *
 * v0.1
 * mk
 * 2012-06-12
 *
 * notes: this file must be saved in "Windows Latin 1" encoding 
 * to keep some weird chars for "quadratmeter" or "kubikmeter"!!!!!!!!!!!!!!!
 * this currently sucks on parsing file w/ mixed line endings!!!!!!!!!!!!!!!!
 * 
 * determining a date from calendar week relies on a given year!!!!!!!!!!!!!!
 *****************************************************************************/

$mysqlHost              = 'localhost';
$mysqlUser              = 'root';
$mysqlPass              = '';
$mysqlDatabase          = 'blitzverlag';
$mysqlTable             = 'aktuelle_anzeigen';

$inDir                  = 'in';
$outDir                 = 'out';
$expectedNoOfFiles      = 8;

$kopfPattern            = "@Kopf1:";
$recordPattern          = "@Fliess:";

$currentIssue           = "";
$startDate              = "";
$currentCategory        = "";

$message                = "JJK import\n";

$recipient              = "";

if ($recipient == "") {
    die("Dunno where to report to...\n";
}
        
$issues = array (
    'OB' => '0',
    'WB' => '1',
    'SB' => '2',
    'RB' => '3',
    'MB' => '4',
    'VPB'=> '5',
    'PB' => '6',
    'VTB'=> '7'
);

$categories = array (
    'Allgemein'         => '',
    'Immobiliengesuche' => '7',
    'Immobilienangebote'=> '20',
    'Wohnungsgesuche'   => '6',
    'Wohnungsangebote'  => '28',
    'Nachmieter'        => '33',
    'Fahrzeugmarkt'     => '1',
    'Wohnwagen'         => '39',
    'Wassersport'       => '10',
    'Verkaufe'          => '2',
    'Verschenke'        => '27',
    'Suche'             => '3',
    'Dienstleistungen'  => '29',
    'Stellengesuche'    => '4',
    'Stellenangebote'   => '25',
    'Tiermarkt'         => '22',
    'Urlaub'            => '21',
    'Geldmarkt'         => '34',
    'Sonstiges'         => '11',
    'Partnerschaft'     => '9',
    'Bars & Clubs'      => '23',
    'Kontakte'          => '23'
);

# file name pattern
$patternFilename        = '#^([^_]+)_AllRub(\d+).TXT#i';

# chiffre pattern
$patternChiffre         = '#Chiffre\s(\d+\/\d+)#';

$aFilesToProcess        = getDirectoryList($inDir, $patternFilename);

if (count($aFilesToProcess) != $expectedNoOfFiles) {
    $message .= "Would like to see " . $expectedNoOfFiles . " files! Got " . count($aFilesToProcess) . " instead... Bailing out...\n";
    mailOut($message);
}

$insertCount            = 0;
$updateCount            = 0;

openDB();

foreach ($aFilesToProcess as $fileName) {
    
    $currentHead        = "";
    
    $splittedFileName   = explode ('_', $fileName);
    $currentIssue       = $splittedFileName[0];
    
    // extract desired calendar week
    $desiredWeekOfYear  = substr(substr($splittedFileName[1], 6), 0, (strlen(substr($splittedFileName[1], 6)) - 4));
        
    $currentYear        = date("Y"); 
    $currentMonth       = date('m');
    
    // we process in december but target is the very first week of the next year...
    if ($currentMonth == '12' && $desiredWeekOfYear == '1') {
        $currentYear ++; // Uh, oh! Being typeless rulez! Sometimes...
    }
        
    $startDate          = startDate($currentYear, $desiredWeekOfYear);
    $endDate            = date ('Y-m-d', strtotime($startDate) + (7 * 24 * 3600));
    
    $message .= "Processing file '" . $fileName . "' from '" . $startDate . "' to '" . $endDate . "'...\n";    
        
    $fp = fopen('./'. $inDir . '/' . $fileName,'r');
    if (!$fp) {
        $message .= "Can't open file '".$fileName."'... Bailing out!\n";
        mailOut($message);
    }
    
    $lineCounter = 0;
    
    while($line = fgets($fp)) {
        
        $lineCounter ++;
        
        $line = str_replace ('@Schlag:', '@Fliess:', $line);        # ummm, don't ask!
        $line = str_replace ('<B>', '', $line);                     # crappy bold tag            
        $line = str_replace ('<$f"Arial">', '', $line);             # strange font defs
        $line = str_replace ('<$f"Wingdings">(', ' Tel. ', $line);  # phone symbol
        $line = str_replace ('<$f"Wingdings">*', ' ', $line);       # letter symbol
        $line = str_replace ('<\@>', '@', $line);                   # @ in email addresses...
        $line = str_replace ('<+>2<+>', '²', $line);                # quadratmeters
        $line = str_replace ('<+>3<+>', '³', $line);                # cubicmeters
        $line = str_replace ('  ', ' ', $line);                     # get rid of double blanks
        
        if (stristr($line, $kopfPattern)) { // this is head
            
            $head = extractHead($line);
            $currentHead = $head;
            
            // Look if there's more stuff (aka record). 
            // This happens if the heads are separated from the records just by CR (instead of CRLF or LF).
            
            if (stristr($line, $recordPattern)) {
                
                // if so, check for chiffre...
                
                if (preg_match ($patternChiffre,  $line, $hits)) {
                    $chiffre = $hits[1];
                } else {
                    $chiffre = false;
                }
                
                // ...and extract record
                $record = extractRecord($line);
                
            } else {
                
                // this line contains just heads...
                $record = "";
                
            }
                                            
        } else if (stristr($line, $recordPattern)) { // this is record
            
            // extract chiffre
            if (preg_match ($patternChiffre,  $line, $hits)) {
                $chiffre = $hits[1];
            } else {
                $chiffre = false;
            }
            
            // ectract record from line
            $record = extractRecord($line);

        } else {
            mysql_close();
            $message .= ("Error!!! Looks like the line #" . $lineCounter . " is somewhat garbled...\n");
            mailOut($message);            
        }        
        
        // conditional insert or update of record
        
        $previouslyWrittenRecordId = false;
        $previouslyWrittenRecordId = fetchRecord($record, $startDate);
                
        if ($previouslyWrittenRecordId != false) {
            updateRecord($previouslyWrittenRecordId, $issues[$currentIssue]);
            $updateCount ++;
        } else {
            if ($record != "") {
                insertRecord($record, $startDate, $endDate, $issues[$currentIssue], $categories[$currentHead], $chiffre);
                $insertCount ++;
            }
        }
            
    }
    
    // we're done. move file out of the folder...
    moveProcessedFile($fileName);

}

$message .= "I just inserted " . $insertCount ." records and updated " . $updateCount . " of them... Bye!\n";
mysql_close();
mailOut($message);

/****************************************************************************
 * extracts category from a given line
 *
 *
 *
 ****************************************************************************/
function extractHead($line) 
{
    global $kopfPattern;
            
    $lineWithoutHead = substr($line, strlen($kopfPattern), strlen($line));    
    $posOfSecondAt = strpos($lineWithoutHead, @"@");
    
    if ($posOfSecondAt == FALSE) {
    
        $category = trim($lineWithoutHead);
    
    } else {
    
        $category = trim(substr($lineWithoutHead, 0, $posOfSecondAt));    
    
    }
    
    return $category;

}

/****************************************************************************
 * extracts actual record from a given line
 *
 *
 *
 ****************************************************************************/
function extractRecord ($line) 
{
    
    global $kopfPattern;
    global $recordPattern;
    
    if (substr($line, 1,1) == "K") {
        $lineWithoutHead = substr($line, strlen($kopfPattern), strlen($line));
        $parts = explode($recordPattern, $lineWithoutHead);
        return trim($parts[1]);
    } else {
        return trim(substr($line, strlen($recordPattern), strlen($line)));
    }

}

/****************************************************************************
 * builds array of files (w/ names matching a pattern) in a given diretory.
 *
 *
 *
 ****************************************************************************/
function getDirectoryList($directory, $pattern = '/./') 
{
    $results = array();
    $handler = opendir($directory);

    while ($fileName = readdir($handler)) {
        if(preg_match ($pattern,  $fileName, $hits)) {
            $results[] = $fileName;
        }
    }

    closedir($handler);
    return $results;

}

/****************************************************************************
 * opens connection to database.
 *
 *
 *
 ****************************************************************************/
function openDB() 
{
    
    global $mysqlHost, $mysqlUser, $mysqlPass, $mysqlDatabase;
    
    $dbConn = mysql_connect($mysqlHost, $mysqlUser, $mysqlPass);
    mysql_select_db($mysqlDatabase, $dbConn);
    
}

/****************************************************************************
 * fetches a particular record's id from db.
 * returns false if record does not exists.
 *
 *
 ****************************************************************************/
function fetchRecord($record, $startDate) 
{

    global $mysqlTable;
    global $message;        

    $res = false;

    $query = 'SELECT id FROM ' . $mysqlTable . ' WHERE anz_text = "' . mysql_escape_string($record) . '" AND date_start = "' . $startDate . '";';
    $result = mysql_query($query);

    if (!$result) {
        $message .= "Error: " . mysql_error() . "\n";
        $message .= "Query: " . $query ."\n";
        mysql_close();
        mailOut($message);
    }

    while ($row = mysql_fetch_assoc($result)) {
        $res = $row['id'];
    }
        
    return $res;

}

/****************************************************************************
 * insterts a record to db.
 *
 *
 *
 ****************************************************************************/
function insertRecord($record, $startDate, $endDate, $dIssue, $dCategory, $chiffre) 
{

    global $mysqlTable;
    global $message;

    if ($dIssue == 0) {
        $issueFields = 'verlag_id1, verlag_id2, verlag_id3, verlag_id4, verlag_id5, verlag_id6, verlag_id7';
        $issueValues = '1, 1, 1, 1, 1, 1, 1';
    } else {
        $issueFields = 'verlag_id'.$dIssue;
        $issueValues = '1';
    }
    
    if ($chiffre != false) {
        $chiffreFields = 'chiffre, chiffre_nr';
        $chiffreValues = '1, "' . str_replace("/", "_", $chiffre) . '"';
    } else {
        $chiffreFields = 'chiffre';
        $chiffreValues = '0';
    }
    
    $query = 'INSERT INTO ' . $mysqlTable . ' (anz_text, date_start, date_end, rubrik, zeilen, created, '. $issueFields .', ' . $chiffreFields . ' ) VALUES ("' . mysql_escape_string($record) . '", "'.$startDate.'", "'.$endDate.'", ' . $dCategory . ',3, NOW(), ' . $issueValues . ', ' . $chiffreValues . ' );';
    $result = mysql_query($query);
    
    if (!$result) {
        $message .= "Error: " . mysql_error() . "\n";
        $message .= "Query: " . $query ."\n";
        mysql_close();
        mailOut($message);
    }
    

}

/****************************************************************************
 * updates a record in db.
 *
 *
 *
 ****************************************************************************/
function updateRecord($recordId, $dIssue) 
{
    global $message;   
    
    if ($dIssue == 0) {
        return;
    }

    global $mysqlTable;

    $query = 'UPDATE ' . $mysqlTable . ' SET verlag_id'.$dIssue.' = 1 WHERE id = ' . $recordId;
    $result = mysql_query($query);
    
    if (!$result) {
        $message .= "Error: " . mysql_error() . "\n";
        $message .= "Query: " . $query ."\n";
        mysql_close();
        mailOut($message);
        die($message);
    }
    
}

/****************************************************************************
 * moves a file from one diretory to another.
 *
 *
 *
 ****************************************************************************/
function moveProcessedFile($fileName) 
{

    global $inDir, $outDir;

    if (copy('./' . $inDir . '/'  . $fileName, './' . $outDir . '/'  . $fileName)) {
        unlink('./' . $inDir . '/'  . $fileName);
    }

}

/****************************************************************************
 * determines the date of the first sunday from a given calendar week number
 * and a year.
 *
 *
 ****************************************************************************/
function startDate($year, $week) 
{ 
    $firstCalendarWeek = mktime(0, 0, 0, 1, 4, $year); // 4th of January is in calendar week #1 for sure!
    $monday = $firstCalendarWeek + 86400 * (7 * ($week - 1)  - date('w', $firstCalendarWeek) + 1);
    $sunday = $monday + 86400 * 6;
    return date('Y-m-d', $sunday);
}  

function mailOut($message)
{
    global $recipient;
    
    mail($recipient, "JJK import", $message);
    die("Done!");
}
/*

look at this amazing attempt of the incredible eidberger ---

function pase($fileName) {
    $sF = $fileName; # Dateiname
    $aR = array ('<$f"Wingdings">(' => 'W', '<$f"Wingdings">*' => 'Eur'); # Ersetzungstabelle fuer die Fluegel
    $sD = strip_tags (str_replace (array_keys ($aR), array_values ($aR), file_get_contents ($sF))); # Datei laden und filtern
    $aC = preg_split ('/@Kopf1:/si', $sD, NULL, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY); # Daten in Bloecke aufsplitten

    foreach ($aC as $sC) { # alle Bloecke abarbeiten
        preg_match ('/^(.+)\s+(.+)$/Usi', $sC, $aX); # Kategorie-Titel und Rest aus Block holen
        preg_match_all ('/\@Fliess\:(.+)/i', $aX[2], $aI); # aus Rest die Elemente ermitteln
        var_dump (array ('title' => $aX[1], 'items' => $aI[1])); # Ausgabe
    }
}
*/

/*
CREATE DATABASE test;
CREATE TABLE `aktuelle_anzeigen` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `anzeigen_typ` tinyint(1) NOT NULL default '0',
  `rubrik` int(10) NOT NULL default '0',
  `anz_text` text NOT NULL,
  `zeilen` int(10) unsigned NOT NULL default '0',
  `date_start` date NOT NULL default '0000-00-00',
  `date_end` date NOT NULL default '0000-00-00',
  `chiffre` varchar(10) NOT NULL default '',
  `chiffre_nr` varchar(20) NOT NULL default '',
  `verlag_id1` int(1) NOT NULL default '0',
  `verlag_id2` int(1) NOT NULL default '0',
  `verlag_id3` int(1) NOT NULL default '0',
  `verlag_id4` int(1) NOT NULL default '0',
  `verlag_id5` int(1) NOT NULL default '0',
  `verlag_id6` int(1) NOT NULL default '0',
  `verlag_id7` int(1) NOT NULL default '0',
  `layout` char(1) NOT NULL default '0',
  `bilder` char(1) NOT NULL default '0',
  `kunden_id` int(14) NOT NULL default '0',
  `created` datetime NOT NULL default '1970-01-01 00:00:00',
  `created_in` smallint(5) unsigned NOT NULL default '0',
  `datum` datetime NOT NULL default '0000-00-00 00:00:00',
  `access` timestamp(14) NOT NULL,
  PRIMARY KEY  (`id`),
  UNIQUE KEY `id` (`id`),
  KEY `chiffre_nr` (`chiffre_nr`),
  KEY `kunden_id` (`kunden_id`),
  KEY `verlag_id5` (`verlag_id5`),
  FULLTEXT KEY `anz_text` (`anz_text`)
) TYPE=MyISAM;

ALTER TABLE aktuelle_anzeigen AUTO_INCREMENT = 400000;

 */



?>