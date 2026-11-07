<?php

/** SAP HANA driver for Adminer (PDO_ODBC + HDBODBC)
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

if (isset($_GET["hana"])) {
	define("Adminer\DRIVER", "hana");

	if (extension_loaded("pdo_odbc")) {
		class Db extends PdoDb {
			public $extension = "PDO_ODBC";

			function attach(array $server, $username, $password) {
				$host = $server["host"];
				$port = $server["port"];
				$path = $server["path"];
				$raw = SERVER;

				if (stripos($raw, "Driver=") !== false || stripos($raw, "ServerNode=") !== false) {
					$dsn = "odbc:" . $raw;
				} elseif ($host === "" && $port === "" && $path === "" && $raw !== "") {
					$dsn = "odbc:" . $raw;
				} else {
					// Reject ODBC metacharacters in parsed parts (DSN injection).
					foreach (array($host, $port, $path) as $part) {
						if ($part !== "" && preg_match('~[;=]~', $part)) {
							return "Invalid server (contains ';' or '='). Use a full ODBC string if needed.";
						}
					}
					$node = ($host !== "" ? $host : "localhost") . ($port !== "" ? ":$port" : ":30015");
					$driver = getenv("ADMINER_HANA_ODBC_DRIVER") ?: "HDBODBC";
					$dsn = "odbc:Driver={$driver};ServerNode={$node}";
					if ($path !== "") {
						$dsn .= ";DatabaseName=" . ltrim($path, "/");
					}
				}

				$return = $this->dsn($dsn, $username, $password);
				if ($return === "") {
					$info = get_val("SELECT VALUE FROM M_DATABASE", 0, $this);
					if ($info) {
						$this->server_info = $info;
					}
				}
				return $return;
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
				return parent::query($F, $en);
			}
		}
	}

	class Driver extends SqlDriver {
		static $extensions = array("PDO_ODBC");
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
		return get_vals("SELECT SCHEMA_NAME FROM SYS.SCHEMAS WHERE HAS_PRIVILEGES = 'TRUE' ORDER BY 1");
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
		// Qualify SYS.* — unqualified VIEWS/TABLES can resolve to a user object in CURRENT_SCHEMA.
		$return = array();
		foreach (get_rows(
			"SELECT TABLE_NAME AS name, 'table' AS type FROM SYS.TABLES WHERE SCHEMA_NAME = CURRENT_SCHEMA
			UNION ALL
			SELECT VIEW_NAME, 'view' FROM SYS.VIEWS WHERE SCHEMA_NAME = CURRENT_SCHEMA
			ORDER BY 1"
		) as $row) {
			$return[$row["name"]] = $row["type"];
		}
		return $return;
	}

	function count_tables(array $databases): array {
		$return = array();
		foreach ($databases as $db) {
			$return[$db] = (int) get_val(
				"SELECT (SELECT COUNT(*) FROM SYS.TABLES WHERE SCHEMA_NAME = " . q($db) . ")
				+ (SELECT COUNT(*) FROM SYS.VIEWS WHERE SCHEMA_NAME = " . q($db) . ") FROM DUMMY"
			);
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
			WHERE SCHEMA_NAME = CURRENT_SCHEMA AND TABLE_NAME = " . q($table) . " AND IS_PRIMARY_KEY = 'TRUE'
			ORDER BY POSITION"
		) as $col) {
			$pks[$col] = true;
		}
		$priv = hana_readonly()
			? array("select" => 1, "where" => 1, "order" => 1)
			: array("insert" => 1, "select" => 1, "update" => 1, "where" => 1, "order" => 1);
		foreach (get_rows(
			"SELECT COLUMN_NAME, DATA_TYPE_NAME, LENGTH, SCALE, IS_NULLABLE, DEFAULT_VALUE
			FROM SYS.TABLE_COLUMNS
			WHERE SCHEMA_NAME = CURRENT_SCHEMA AND TABLE_NAME = " . q($table) . "
			ORDER BY POSITION"
		) as $row) {
			$type = strtolower($row["DATA_TYPE_NAME"]);
			$length = $row["LENGTH"];
			if ($row["SCALE"] !== null && $row["SCALE"] !== "" && preg_match('~decimal|number~', $type)) {
				$length = $row["LENGTH"] . "," . $row["SCALE"];
			}
			$return[$row["COLUMN_NAME"]] = array(
				"field" => $row["COLUMN_NAME"],
				"full_type" => $type . ($length !== null && $length !== "" ? "($length)" : ""),
				"type" => $type,
				"length" => $length,
				"default" => $row["DEFAULT_VALUE"],
				"null" => ($row["IS_NULLABLE"] == "TRUE"),
				"auto_increment" => false,
				"privileges" => $priv,
				"primary" => isset($pks[$row["COLUMN_NAME"]]),
			);
		}
		return $return;
	}

	function indexes(string $table, ?Db $connection2 = null): array {
		$return = array();
		foreach (get_rows(
			"SELECT CONSTRAINT_NAME, COLUMN_NAME, IS_PRIMARY_KEY, IS_UNIQUE_KEY
			FROM SYS.CONSTRAINTS
			WHERE SCHEMA_NAME = CURRENT_SCHEMA AND TABLE_NAME = " . q($table) . "
			AND (IS_PRIMARY_KEY = 'TRUE' OR IS_UNIQUE_KEY = 'TRUE')
			ORDER BY CONSTRAINT_NAME, POSITION",
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
	}

	protected $translations = array(
		'en' => array('' => 'SAP HANA driver with optional read-only mode'),
	);
}

}
