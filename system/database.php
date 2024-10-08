<?php
/**
 * Database functions
 * Not included in class to shorten typing effort.
 */

connect_db();
function connect_db()
{
    global $db;
    global $cfg;
    @$db = new mysqli(DATABASE_HOSTNAME, DATABASE_USERNAME, DATABASE_PASSWORD);
    if ($connection_error = mysqli_connect_error()) {
        $errors[] = 'There was an error trying to connect to database at ' . DATABASE_HOSTNAME . ':<br><b>' . $connection_error . '</b>';
        require 'templates/error_template.php';
        die();
    }
    mysqli_select_db($db, DATABASE_DATABASE) or error_out('<b>Error:</b><i> ' . mysqli_error($db) . '</i><br>
		This usually means that MySQL does not have a database called <b>' . DATABASE_DATABASE . '</b>.<br><br>
		Create that database and import some structure into it from <b>doc/database.sql</b> file:<br>
		<ol>
		<li>Open database.sql</li>
		<li>Copy all the SQL code</li>  
		<li>Go to phpMyAdmin</li>
		<li>Create a database called <b>' . DATABASE_DATABASE . '</b></li>
		<li>Open it and go to <b>SQL</b> tab</li>
		<li>Paste the copied SQL code</li>
		<li>Hit <b>Go</b></li>
		</ol>', 500);

    // Switch to utf8
    if (!$db->set_charset("utf8")) {
        trigger_error(sprintf("Error loading character set utf8: %s\n", $db->error));
        exit();
    }


}

function q($sql, & $query_pointer = NULL, $debug = FALSE)
{
    global $db;
    if ($debug) {
        print "<pre>$sql</pre>";
    }
    $query_pointer = mysqli_query($db, $sql) or db_error_out();
    switch (substr($sql, 0, 6)) {
        case 'SELECT':
            exit("q($sql): Please don't use q() for SELECTs, use get_one() or get_first() or get_all() instead.");
        case 'UPDA':
            exit("q($sql): Please don't use q() for UPDATEs, use update() instead.");
        default:
            return mysqli_affected_rows($db);
    }
}

function get_one($sql, $debug = FALSE)
{
    global $db;

    if ($debug) { // kui debug on TRUE
        print "<pre>$sql</pre>";
    }
    switch (substr(trim($sql), 0, 6)) {
        case 'SELECT':
            $q = mysqli_query($db, $sql) or db_error_out();
            $result = mysqli_fetch_array($q);
            return empty($result) ? NULL : $result[0];
        default:
            exit('get_one("' . $sql . '") failed because get_one expects SELECT statement.');
    }
}

function get_all($sql)
{
    global $db;
    $q = mysqli_query($db, $sql) or db_error_out();
    while (($result[] = mysqli_fetch_assoc($q)) || array_pop($result)) {
        ;
    }
    return $result;
}

function get_first($sql)
{
    global $db;
    $q = mysqli_query($db, $sql) or db_error_out();
    $first_row = mysqli_fetch_assoc($q);
    return empty($first_row) ? array() : $first_row;
}

function db_error_out($sql = null)
{
    global $db;

    header($_SERVER["SERVER_PROTOCOL"] . " 500 Internal server error", true, 500);

    define('PREG_DELIMITER', '/');

    $db_error = mysqli_error($db);

    if (strpos($db_error, 'You have an error in SQL syntax') !== FALSE) {
        $db_error = '<b>Syntax error in</b><pre> ' . substr($db_error, 135) . '</pre>';

    }


    $backtrace = debug_backtrace();

    $file = $backtrace[1]['file'];
    $file = str_replace(dirname(__DIR__), '', $file);

    $line = $backtrace[1]['line'];
    $function = isset($backtrace[1]['function']) ? $backtrace[1]['function'] : NULL;

    // Get arguments
    $args = isset($backtrace[1]['args']) ? $backtrace[1]['args'] : NULL;

    // Protect the next statement failing with "Malformed UTF-8 characters, possibly incorrectly encoded" error when $args contains binary
    array_walk_recursive($args, function (&$item) {

        // Truncate item to 1000 bytes if it is longer
        if (strlen($item) > 1000) $item = mb_substr($item, 0, 1000);


        $item = mb_convert_encoding($item, 'UTF-8', 'UTF-8');

        $item = preg_replace('/[\x00-\x1F\x7F-\xFF]/', '.', $item);
    });

    // Serialize arguments
    $args = json_encode($args, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

    // Fault highlight
    preg_match("/check the manual that corresponds to your MySQL server version for the right syntax to use near '([^']+)'./", $db_error, $output_array);
    if (!empty($output_array[1])) {
        $fault = $output_array[1];
        $fault_quoted = preg_quote($fault);


        $args = preg_replace(PREG_DELIMITER . "(\w*\s*)$fault_quoted" . PREG_DELIMITER, "<span class='fault'>\\1$fault</span>", $args);

        $args = stripslashes($args);
    }


    $location = "<b>$file</b><br><b>$line</b>: ";
    if (!empty($function)) {

        $args = str_replace("SELECT", '<br>SELECT', $args);
        $args = str_replace("\n", '<br>', $args);
        $args = str_replace("\t", '&nbsp;', $args);


        $code = "$function(<span style=\" font-family: monospace; ;padding:0; margin:0\">$args</span>)";
        $location .= "<span class=\"line-number-position\">&#x200b;<span class=\"line-number\">$code";

    }


    // Generate stack trace
    $e = new Exception();
    $trace = print_r(preg_replace('/#(\d+) \//', '#$1 ', str_replace(dirname(dirname(__FILE__)), '', $e->getTraceAsString())), 1);
    $trace = nl2br(preg_replace('/(#1.*)\n/', "<b>$1</b>\n", $trace));

    $output = '<h1>Database error</h1>' .
        '<p>' . $db_error . '</p>' .
        '<p><h3>Location</h3> ' . $location . '<br>' .
        '<p><h3>Stack trace</h3>' . $trace . '</p>';


    if (isset($_GET['ajax'])) {
        ob_end_clean();
        echo strip_tags($output);

    } else {
        $errors[] = $output;
        require 'templates/error_template.php';
    }

    die();

}

/**
 * @param $table string The name of the table to be inserted into.
 * @param $data array Array of data. For example: array('field1' => 'mystring', 'field2' => 3);
 * @return bool|int Returns the ID of the inserted row or FALSE when fails.
 */
function insert($table, $data = [])
{
    global $db;
    if ($table and is_array($data)) {
        $values = implode(',', escape($data));
        $values = $values ? "SET {$values} ON DUPLICATE KEY UPDATE {$values}":'() VALUES()';
        $sql = "INSERT INTO `{$table}` $values";
        $q = mysqli_query($db, $sql) or db_error_out($sql);
        $id = mysqli_insert_id($db);
        return ($id > 0) ? $id : FALSE;
    } else {
        return FALSE;
    }
}

function update($table, array $data, $where)
{
    global $db;
    if ($table and is_array($data) and !empty($data)) {
        $values = implode(',', escape($data));

        if (!empty($where)) {
            $sql = "UPDATE `{$table}` SET {$values} WHERE {$where}";
        } else {
            $sql = "UPDATE `{$table}` SET {$values}";
        }
        $id = mysqli_query($db, $sql) or db_error_out();
        return ($id > 0) ? $id : FALSE;
    } else {
        return FALSE;
    }
}

function escape(array $data)
{
    global $db;
    $values = array();
    if (!empty($data)) {
        foreach ($data as $field => $value) {
            if ($value === NULL) {
                $values[] = "`$field`=NULL";
            } elseif (is_array($value) && isset($value['no_escape'])) {
                $values[] = "`$field`=" . mysqli_real_escape_string($db, $value['no_escape']);
            } else {
                $values[] = "`$field`='" . mysqli_real_escape_string($db, $value) . "'";
            }
        }
    }
    return $values;
}