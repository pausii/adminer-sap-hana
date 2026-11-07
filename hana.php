<?php

/** SAP HANA driver for Adminer (ext/odbc + HDBODBC)
* Read-only mode: login checkbox (shown only for System = SAP HANA) + nav toggle; default ON.
* Server: host:30015 | ODBC DSN name | Driver={HDBODBC};ServerNode=host:30015;encrypt=true
* @author Ahmad Pausi
* @license https://opensource.org/licenses/MIT MIT License
*/

namespace Adminer {

add_driver("hana", "SAP HANA");

function hana_readonly(): bool {
	return (bool) idx($_SESSION, "hana_readonly", true);
}

function hana_set_readonly(bool $on): void {
	$_SESSION["hana_readonly"] = $on;
}

/** True when the statement is allowed in read-only mode (deny-by-default). */
function hana_query_allowed(string $query): bool {
	$q = preg_replace('~/\*.*?\*/~s', " ", $query);
	$q = preg_replace('~--[^\n]*~', " ", $q);
	$q = rtrim(trim((string) $q), ";");
	if ($q === "" || strpos($q, ";") !== false) {
		return false; // empty or multi-statement
	}
	// SET SCHEMA "NAME" only
	if (preg_match('~^SET\s+SCHEMA\s+"[^"]+"\s*$~i', $q) || preg_match('~^SET\s+SCHEMA\s+[A-Za-z_][A-Za-z0-9_]*\s*$~i', $q)) {
		return true;
	}
	// Single SELECT / WITH…SELECT / SHOW / EXPLAIN / DESCRIBE — no INTO / FOR UPDATE
	if (!preg_match('~^(SELECT|WITH|SHOW|EXPLAIN|DESCRIBE|DESC)\b~i', $q)) {
		return false;
	}
	if (preg_match('~\b(INTO|FOR\s+UPDATE|INSERT|UPDATE|DELETE|MERGE|UPSERT|REPLACE|CREATE|DROP|ALTER|TRUNCATE|CALL|EXEC)\b~i', $q)) {
		return false;
	}
	return true;
}

/** PDO_ODBC::quote() is unimplemented and returns false, which deletes string literals from SQL. */
function hana_q(string $value): string {
	return "'" . str_replace("'", "''", $value) . "'";
}

function hana_schema_sql(): string {
	return (DB != "" ? hana_q(DB) : "CURRENT_SCHEMA");
}

/** SAP Business One tables people actually open. The sidebar shows only those that exist. */
function hana_common_tables(): array {
	return array(
		"OCRD", "CRD1", "OCPR", "OITM", "ITM1", "OITW", "OITB", "OWHS", "OSLP", "OCTG", "OCRG", "OACT", "OHEM", "OUSR",
		"OQUT", "QUT1", "ORDR", "RDR1", "ODLN", "DLN1", "ORDN", "RDN1", "OINV", "INV1", "ORIN", "RIN1",
		"OPQT", "PQT1", "OPOR", "POR1", "OPDN", "PDN1", "ORPD", "RPD1", "OPCH", "PCH1", "ORPC", "RPC1",
		"OIGN", "IGN1", "OIGE", "IGE1", "OWTR", "WTR1", "OINC", "INC1", "OBTN", "OBTQ", "OSRN", "OITL", "ITL1",
		"OJDT", "JDT1", "OVPM", "VPM2", "ORCT", "RCT2", "OFPR",
		"OWOR", "WOR1", "OITT", "ITT1",
	);
}

if (isset($_GET["hana"])) {
	define("Adminer\DRIVER", "hana");

	if (extension_loaded("odbc")) {
		/** Buffered ODBC result. PDO_ODBC's SQLBindCol makes HDBODBC report a row count and return no rows. */
		class HanaResult {
			public $num_rows = 0;
			public $_offset = 0;
			private $buffer = array();
			private $pos = 0;
			private $names = array();

			function fill($result) {
				$width = odbc_num_fields($result);
				for ($i = 1; $i <= $width; $i++) {
					$name = (string) odbc_field_name($result, $i);
					$this->names[] = ($name !== "" ? $name : ("col" . $i));
				}
				odbc_binmode($result, ODBC_BINMODE_RETURN);
				odbc_longreadlen($result, 1048576);
				while (odbc_fetch_row($result)) {
					$row = array();
					for ($i = 1; $i <= $width; $i++) {
						$value = odbc_result($result, $this->names[$i - 1]);
						$row[] = ($value === false ? null : $value);
					}
					$this->buffer[] = $row;
				}
				$this->num_rows = count($this->buffer);
			}

			function fetch_assoc() {
				if (!isset($this->buffer[$this->pos])) {
					return false;
				}
				$row = $this->buffer[$this->pos++];
				$out = array();
				foreach ($row as $i => $value) {
					$out[$this->names[$i]] = $value;
				}
				return $out;
			}

			function fetch_row() {
				$row = $this->fetch_assoc();
				return (is_array($row) ? array_values($row) : $row);
			}

			function fetch_field() {
				$i = $this->_offset++;
				$name = (isset($this->names[$i]) && $this->names[$i] !== "" ? $this->names[$i] : ("col" . ($i + 1)));
				return (object) array("name" => $name, "native_type" => "");
			}
		}

		class Db extends SqlDb {
			public $extension = "ODBC";
			private $odbc;

			function attach(array $server, $username, $password) {
				$host = $server["host"];
				$port = $server["port"];
				$path = $server["path"];
				$raw = SERVER;

				if (stripos($raw, "Driver=") !== false || stripos($raw, "ServerNode=") !== false) {
					$dsn = $raw;
				} elseif ($host === "" && $port === "" && $path === "" && $raw !== "") {
					$dsn = $raw;
				} else {
					// Reject ODBC metacharacters in parsed parts (DSN injection).
					foreach (array($host, $port, $path) as $part) {
						if ($part !== "" && preg_match('~[;=]~', $part)) {
							return "Invalid server (contains ';' or '='). Use a full ODBC string if needed.";
						}
					}
					$node = ($host !== "" ? $host : "localhost") . ($port !== "" ? ":$port" : ":30015");
					$driver = getenv("ADMINER_HANA_ODBC_DRIVER") ?: "HDBODBC";
					$dsn = "Driver={$driver};ServerNode={$node};CHAR_AS_UTF8=1";
					if ($path !== "") {
						$dsn .= ";DatabaseName=" . ltrim($path, "/");
					}
				}
				$dsn = preg_replace('~^odbc:~i', '', $dsn);

				$this->odbc = @odbc_connect($dsn, $username, $password, SQL_CUR_USE_DRIVER);
				if (!$this->odbc) {
					$err = odbc_errormsg();
					return ($err ? $err : "ODBC connection failed.");
				}
				$this->server_info = "";
				$info = get_val("SELECT VALUE FROM M_DATABASE", 0, $this);
				if ($info) {
					$this->server_info = $info;
				}
				return "";
			}

			function quote($string) {
				return hana_q((string) $string);
			}

			function select_db($database) {
				return (bool) $this->query("SET SCHEMA " . idf_escape($database));
			}

			function query($F, $en = false) {
				if (hana_readonly() && !hana_query_allowed((string) $F)) {
					$this->error = "Read-only mode: write queries are blocked.";
					$this->errno = 1;
					return false;
				}
				// page=last asks Adminer for COUNT(*). That scans a live table.
				if (isset($_GET["page"]) && $_GET["page"] === "last" && preg_match('~^\\s*SELECT\\s+COUNT\\s*\\(~i', (string) $F)) {
					$this->error = "Whole-table COUNT is disabled on this live connection.";
					$this->errno = 1;
					return false;
				}
				$result = @odbc_exec($this->odbc, (string) $F);
				$this->error = "";
				if (!$result) {
					$this->errno = odbc_error($this->odbc);
					$this->error = odbc_errormsg($this->odbc);
					if (!$this->error) {
						$this->error = "Query failed.";
					}
					return false;
				}
				if (!odbc_num_fields($result)) {
					$rows = odbc_num_rows($result);
					$this->affected_rows = ($rows > 0 ? $rows : 0);
					odbc_free_result($result);
					return true;
				}
				$statement = new HanaResult();
				$statement->fill($result);
				odbc_free_result($result);
				return $statement;
			}
		}
	}

	class Driver extends SqlDriver {
		static $extensions = array("ODBC");
		static $jush = "hana";
		static $serverPorts = array(30015, 30013, 30041, 443);

		public $insertFunctions = array(
			"date|timestamp" => "CURRENT_DATE|CURRENT_TIMESTAMP",
		);
		public $editFunctions = array(
			"integer|decimal|real|double|smallint|tinyint|bigint" => "+/-",
			"char|varchar|nvarchar|alphanum|shorttext|clob|nclob" => "||",
		);
		public $functions = array("LENGTH", "LOWER", "UPPER", "ROUND", "IFNULL");
		public $grouping = array("AVG", "COUNT", "COUNT DISTINCT", "MAX", "MIN", "SUM");

		function __construct(Db $connection) {
			parent::__construct($connection);
			$this->types = array(
				lang("Numbers") => array(
					"tinyint" => 3, "smallint" => 5, "integer" => 10, "bigint" => 19,
					"decimal" => 34, "smalldecimal" => 16, "real" => 7, "double" => 15,
				),
				lang("Date and time") => array(
					"date" => 10, "time" => 18, "timestamp" => 27, "seconddate" => 19,
				),
				lang("Strings") => array(
					"varchar" => 5000, "nvarchar" => 5000, "alphanum" => 127,
					"shorttext" => 5000, "char" => 2000, "nchar" => 2000,
					"clob" => 0, "nclob" => 0, "text" => 0, "bintext" => 0,
				),
				lang("Binary") => array("varbinary" => 5000, "blob" => 0),
				lang("Networks") => array("boolean" => 1),
			);
		}

		function operators($tableStatus = null) {
			return array("=", "<", ">", "<=", ">=", "!=", "LIKE", "LIKE %%", "IN", "IS NULL", "NOT LIKE", "NOT IN", "IS NOT NULL", "SQL");
		}

		function allFields() {
			return array(); // shortcut: one DESCRIBE per table; 11k tables never finishes
		}

		function insert($table, array $set) {
			if (hana_readonly()) {
				return false;
			}
			return parent::insert($table, $set);
		}

		function update($table, array $set, $queryWhere, $limit = 0, $separator = "\n") {
			if (hana_readonly()) {
				return false;
			}
			return parent::update($table, $set, $queryWhere, $limit, $separator);
		}

		function delete($table, $queryWhere, $limit = 0) {
			if (hana_readonly()) {
				return false;
			}
			return parent::delete($table, $queryWhere, $limit);
		}
	}

	function idf_escape(string $idf): string {
		return '"' . str_replace('"', '""', $idf) . '"';
	}

	function table(string $idf): string {
		return idf_escape($idf);
	}

	function get_databases(bool $flush): array {
		$return = get_vals("SELECT SCHEMA_NAME FROM SYS.SCHEMAS WHERE HAS_PRIVILEGES = 'TRUE'");
		sort($return, SORT_STRING);
		return $return;
	}

	function limit(string $query, string $where, ?int $limit, $offset = 0, $separator = " "): string {
		return " $query$where" . ($limit !== null ? $separator . "LIMIT $limit" . ($offset ? " OFFSET $offset" : "") : "");
	}

	function limit1(string $table, string $query, string $where, $separator = "\n"): string {
		return limit($query, $where, 1, 0, $separator);
	}

	function db_collation(string $db, array $collations) {
	}

	function logged_user(): string {
		return (string) get_val("SELECT CURRENT_USER FROM DUMMY");
	}

	function tables_list(): array {
		// SYS.TABLES.TABLE_NAME is valid but empty for this schema.
		// SYS.VIEWS has no TABLE_NAME (that was the red error). Use SYS.OBJECTS.
		// shortcut: sidebar is the common-table map; Find still searches every name, 50 at a time
		$schema = (DB != "" ? hana_q(DB) : "CURRENT_SCHEMA");
		$find = trim((string) idx($_GET, "hana_find", ""));
		if ($find !== "") {
			$like = " AND UPPER(OBJECT_NAME) LIKE UPPER(" . hana_q("%$find%") . ")";
			$limit = 50;
		} else {
			$in = array();
			foreach (hana_common_tables() as $name) {
				$in[] = hana_q($name);
			}
			$like = " AND OBJECT_NAME IN (" . implode(", ", $in) . ")";
			$limit = count($in);
		}
		$return = array();
		$rows = get_rows(
			"SELECT OBJECT_NAME, OBJECT_TYPE FROM SYS.OBJECTS WHERE SCHEMA_NAME = $schema AND OBJECT_TYPE IN ('TABLE', 'VIEW')$like ORDER BY OBJECT_NAME LIMIT $limit",
			null,
			""
		);
		$add = function ($row) use (&$return) {
			$vals = array_values($row);
			$name = trim((string) $vals[0]);
			$type = strtoupper(trim((string) (isset($vals[1]) ? $vals[1] : "")));
			foreach ($row as $key => $value) {
				if (preg_match('~OBJECT_NAME$~i', (string) $key)) {
					$name = trim((string) $value);
				} elseif (preg_match('~OBJECT_TYPE$~i', (string) $key)) {
					$type = strtoupper(trim((string) $value));
				}
			}
			if ($name === "") {
				return;
			}
			if (strpos($type, "VIEW") !== false) {
				$return[$name] = "view";
			} elseif (strpos($type, "TABLE") !== false) {
				$return[$name] = "table";
			}
		};
		if (!connection()->error) {
			foreach ($rows as $row) {
				$add($row);
			}
			$open = "";
			foreach (array("select", "edit", "table", "view", "create") as $key) {
				if (!empty($_GET[$key]) && is_string($_GET[$key])) {
					$open = $_GET[$key];
					break;
				}
			}
			if ($open !== "" && !isset($return[$open])) {
				foreach (get_rows(
					"SELECT OBJECT_NAME, OBJECT_TYPE FROM SYS.OBJECTS WHERE SCHEMA_NAME = $schema AND OBJECT_NAME = " . hana_q($open) . " AND OBJECT_TYPE IN ('TABLE', 'VIEW')",
					null,
					""
				) as $row) {
					$add($row);
				}
			}
			ksort($return, SORT_STRING);
			return $return;
		}
		return array();
	}

	function count_tables(array $databases): array {
		$return = array();
		foreach ($databases as $db) {
			$return[$db] = 0;
		}
		if (!$return) {
			return $return;
		}
		$in = array();
		foreach ($databases as $db) {
			$in[] = hana_q((string) $db);
		}
		// One catalog aggregate for the database list. Not one COUNT per schema, and not a scan of table data.
		$rows = get_rows(
			"SELECT SCHEMA_NAME, COUNT(*) AS N FROM SYS.OBJECTS WHERE OBJECT_TYPE IN ('TABLE', 'VIEW') AND SCHEMA_NAME IN (" . implode(", ", $in) . ") GROUP BY SCHEMA_NAME",
			null,
			""
		);
		if (connection()->error) {
			return $return;
		}
		foreach ($rows as $row) {
			$vals = array_values($row);
			$name = (string) $vals[0];
			if (isset($return[$name])) {
				$return[$name] = (int) $vals[1];
			}
		}
		return $return;
	}

	function table_status(string $name = "", bool $fast = false): array {
		$return = array();
		$data = ($name != "" ? array($name => "table") : tables_list());
		foreach ($data as $table => $type) {
			$return[$table] = array(
				"Name" => $table,
				"Engine" => $type,
			);
		}
		return $return;
	}

	function is_view(array $tableStatus): bool {
		return ($tableStatus["Engine"] == "view");
	}

	function fk_support(array $tableStatus): bool {
		return false;
	}

	function fields(string $table): array {
		$return = array();
		$pks = array();
		foreach (get_vals(
			"SELECT COLUMN_NAME FROM SYS.CONSTRAINTS
			WHERE SCHEMA_NAME = " . hana_schema_sql() . " AND TABLE_NAME = " . hana_q($table) . " AND IS_PRIMARY_KEY = 'TRUE'"
		) as $col) {
			$pks[$col] = true;
		}
		$priv = hana_readonly()
			? array("select" => 1, "where" => 1, "order" => 1)
			: array("insert" => 1, "select" => 1, "update" => 1, "where" => 1, "order" => 1);
		$rows = get_rows(
			"SELECT COLUMN_NAME, DATA_TYPE_NAME, LENGTH, SCALE, IS_NULLABLE, DEFAULT_VALUE, POSITION
			FROM SYS.TABLE_COLUMNS
			WHERE SCHEMA_NAME = " . hana_schema_sql() . " AND TABLE_NAME = " . hana_q($table)
		);
		usort($rows, function ($a, $b) {
			return (int) idx($a, "POSITION", idx($a, "position", 0)) <=> (int) idx($b, "POSITION", idx($b, "position", 0));
		});
		foreach ($rows as $row) {
			$name = idx($row, "COLUMN_NAME", idx($row, "column_name"));
			$type = strtolower((string) idx($row, "DATA_TYPE_NAME", idx($row, "data_type_name")));
			$length = idx($row, "LENGTH", idx($row, "length"));
			$scale = idx($row, "SCALE", idx($row, "scale"));
			if ($scale !== null && $scale !== "" && preg_match('~decimal|number~', $type)) {
				$length = $length . "," . $scale;
			}
			$return[$name] = array(
				"field" => $name,
				"full_type" => $type . ($length !== null && $length !== "" ? "($length)" : ""),
				"type" => $type,
				"length" => $length,
				"default" => idx($row, "DEFAULT_VALUE", idx($row, "default_value")),
				"null" => (idx($row, "IS_NULLABLE", idx($row, "is_nullable")) == "TRUE"),
				"auto_increment" => false,
				"privileges" => $priv,
				"primary" => isset($pks[$name]),
			);
		}
		return $return;
	}

	function indexes(string $table, ?Db $connection2 = null): array {
		$return = array();
		foreach (get_rows(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, IS_PRIMARY_KEY, IS_UNIQUE_KEY, POSITION
			FROM SYS.CONSTRAINTS
			WHERE SCHEMA_NAME = " . hana_schema_sql() . " AND TABLE_NAME = " . hana_q($table) . "
			AND (IS_PRIMARY_KEY = 'TRUE' OR IS_UNIQUE_KEY = 'TRUE')",
			$connection2
		) as $row) {
			$name = $row["CONSTRAINT_NAME"];
			$return[$name]["type"] = ($row["IS_PRIMARY_KEY"] == "TRUE" ? "PRIMARY" : "UNIQUE");
			$return[$name]["columns"][] = $row["COLUMN_NAME"];
			$return[$name]["lengths"][] = null;
			$return[$name]["descs"][] = null;
		}
		return $return;
	}

	function foreign_keys(string $table): array {
		return array();
	}

	function collations(): array {
		return array();
	}

	function information_schema(?string $db): bool {
		return ($db === "SYS" || $db === "SYSTEM" || $db === "_SYS_STATISTICS");
	}

	function error(): string {
		return h(connection()->error);
	}

	function types(): array {
		return array();
	}

	function explain($connection, $query) {
		return false;
	}

	function convert_field(array $field) {
	}

	function unconvert_field(array $field, $value) {
		return $value;
	}

	function found_rows(array $tableStatus, array $where) {
		// Null makes Adminer run COUNT(*) on the live table.
		$page = (isset($_GET["page"]) && is_numeric($_GET["page"]) ? (int) $_GET["page"] : 0);
		$limit = (isset($_GET["limit"]) && is_numeric($_GET["limit"]) ? (int) $_GET["limit"] : 50);
		if ($page < 0) {
			$page = 0;
		}
		if ($limit < 1) {
			$limit = 50;
		}
		$next = ($page + 2) * $limit;
		$name = (string) idx($tableStatus, "Name", "");
		if ($where || $name === "") {
			return $next; // a filtered COUNT(*) can scan the table
		}
		$n = get_val(
			"SELECT SUM(RECORD_COUNT) FROM SYS.M_TABLES WHERE SCHEMA_NAME = " . hana_schema_sql()
			. " AND TABLE_NAME = " . hana_q($name)
		);
		if (connection()->error) {
			connection()->error = "";
			connection()->errno = 0;
			return $next;
		}
		$n = (int) $n;
		return ($n > $next ? $n : $next);
	}

	function support(string $feature): bool {
		return (bool) preg_match('~^(columns|database|indexes|sql|status|table|view)$~', $feature);
	}
} // isset hana

} // namespace Adminer

namespace {

/** Login checkbox + in-session toggle for SAP HANA read-only mode (default ON). */
class AdminerHanaReadonly extends Adminer\Plugin {

	function __construct() {
		// Persist on auth POST (login() runs too late — after redirect loses $_POST["auth"]).
		if (!empty($_POST["auth"]) && ($_POST["auth"]["driver"] ?? "") === "hana") {
			if (session_status() === PHP_SESSION_NONE) {
				@session_start();
			}
			Adminer\hana_set_readonly(!empty($_POST["auth"]["hana_readonly"]));
		}
	}

	function loginFormField($name, $heading, $value) {
		if ($name != "db") {
			return null;
		}
		$checked = !isset($_SESSION["hana_readonly"]) || $_SESSION["hana_readonly"];
		$show = (Adminer\DRIVER === "hana");
		return $heading . $value
			. "<tr id='hana-readonly-row'" . ($show ? "" : " class='hidden'") . ">"
			. "<th>Read-only<td>" . Adminer\checkbox("auth[hana_readonly]", 1, $checked, "Enable") . "\n"
			. Adminer\script(<<<'JS'
(function () {
	const sel = qs('select[name="auth[driver]"]');
	const row = qs('#hana-readonly-row');
	if (!sel || !row) return;
	const sync = () => row.classList.toggle('hidden', sel.value !== 'hana');
	sel.addEventListener('change', sync);
	sync();
})();
JS);
	}

	function afterConnect() {
		if (!defined("Adminer\DRIVER") || Adminer\DRIVER !== "hana") {
			return;
		}
		if (isset($_POST["hana_readonly_toggle"]) && Adminer\verify_token()) {
			Adminer\restart_session();
			Adminer\hana_set_readonly(!Adminer\hana_readonly());
			Adminer\redirect(Adminer\relative_uri());
		}
	}

	function navigation($missing) {
		if (!defined("Adminer\DRIVER") || Adminer\DRIVER !== "hana" || $missing) {
			return;
		}
		$on = Adminer\hana_readonly();
		echo "<form action='' method='post' style='margin:.5em 0'>";
		echo Adminer\input_token();
		echo "<input type='submit' name='hana_readonly_toggle' value='"
			. Adminer\h($on ? "Read-only: ON (click to allow writes)" : "Read-only: OFF (click to lock)")
			. "'>";
		echo "</form>\n";
		if (defined("Adminer\DB") && Adminer\DB !== "") {
			$find = (string) Adminer\idx($_GET, "hana_find", "");
			echo "<form action=''><p>";
			echo Adminer\hidden_fields_get();
			echo Adminer\input_hidden("db", Adminer\DB);
			foreach (array("ns", "select", "edit", "table", "sql", "dump", "import", "schema", "view", "create") as $key) {
				if (isset($_GET[$key]) && is_string($_GET[$key])) {
					echo Adminer\input_hidden($key, $_GET[$key]);
				}
			}
			echo "<input type='search' name='hana_find' value='" . Adminer\h($find) . "' size='16' placeholder='Find table' autocapitalize='off'> ";
			echo "<input type='submit' value='Find'>";
			echo "</p></form>\n";
		}
	}

	protected $translations = array(
		'en' => array('' => 'SAP HANA driver with optional read-only mode'),
	);
}

}
