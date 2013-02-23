<?php
/**
 * Matcha::connect (MatchaCUP Class)
 * Matcha.php
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 */

include_once('MatchaAudit.php');
include_once('MatchaCUP.php');
include_once('MatchaErrorHandler.php');
include_once('MatchaModel.php');

// Include the Matcha Threads if the PHP Thread class exists
if(class_exists('Thread')) include_once('MatchaThreads.php');

class Matcha
{
	 
	/**
	 * This would be a Sencha Model parsed by getSenchaModel method
	 */
	public static $Relation;
	public static $currentRecord;
	public static $__id;
	public static $__total;
	public static $__freeze = false;
	public static $__conn;
	public static $__app;
	
	/**
	 * function connect($databaseParameters = array()):
	 * Method that make the connection to the database
	 */
	static public function connect($databaseParameters = array())
	{
		try
		{		
			// check for properties first.
			if(!isset($databaseParameters['host']) && 
				!isset($databaseParameters['name']) &&
				!isset($databaseParameters['user']) && 
				!isset($databaseParameters['pass']) &&
				!isset($databaseParameters['app'])) 
				throw new Exception('These parameters are obligatory: host="database ip or hostname", name="database name", user="database username", pass="database password", app="path of your sencha application"');
				
			// Connect using regular PDO Matcha::connect Abstraction layer.
			// but make only a connection, not to the database.
			// and then the database
			self::$__app = $databaseParameters['app'];
			$host = (string)$databaseParameters['host'];
			$port = (int)(isset($databaseParameters['port']) ? $databaseParameters['port'] : '3306');
			$dbName = (string)$databaseParameters['name'];
			$dbUser = (string)$databaseParameters['user'];
			$dbPass = (string)$databaseParameters['pass'];
			self::$__conn = new PDO('mysql:host='.$host.';port='.$port.';', $dbUser, $dbPass, array(
				PDO::MYSQL_ATTR_LOCAL_INFILE => 1,
				PDO::ATTR_PERSISTENT => true
			));
			self::$__conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
			self::$__conn->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
			// check if the database exist.
			self::__createDatabase($dbName);
			self::$__conn->query('USE '.$dbName.';');
			return self::$__conn;
		}
		catch(Exception $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}
	 
	/**
	 * function getLastId():
	 * Get the last insert ID of an insert
	 * this is automatically updated by the store method
	 */
	static public function getLastId()
	{
		return (int)self::$__id;
	}
	
	/**
	 * getTotal:
	 * Get the total records in a select statement
	 * this is automatically updated by the load method
	 */
	static public function getTotal()
	{
		return (int)self::$__total;
	}
	
	/**
	 * freeze($onoff = false):
	 * freeze the database and tables alteration by the Matcha microORM
	 */
	static public function freeze($onoff = false)
	{
		self::$__freeze = (bool)$onoff;
	}
	
	/**
	 * function __createTable():
	 * Method to create a table if does not exist with a BIGINT as id
	 * also if the sencha model has an array on the table go ahead and
	 * proccess the table options. 
	 */
	static protected function __createTable($forcedTable = NULL)
	{
	    try
	    {
            if($forcedTable)
            {
                $table = (string)$forcedTable;
            }
            else
            {
	    	    $table = (string)(is_array(self::$__senchaModel['table']) ? self::$__senchaModel['table']['name'] : self::$__senchaModel['table']);
            }
			self::$__conn->exec('CREATE TABLE IF NOT EXISTS '.$table.' (id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY) '.self::__renderTableOptions().';');
		    
			// if $__senchaModel['table']['data'] is set and there is data upload the data to the table. 
		    if(isset(self::$__senchaModel['table']['data']))
			{
			    $rec = self::$__conn->prepare('SELECT * FROM '.$table);
			    if($rec->rowCount() == 0 && isset(self::$__senchaModel['table']['data']))
			    {
					self::__setSenchaModelData(self::$__senchaModel['table']['data']);
				}
			}
			return true;
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}
	
	/**
	 * function __renderTableOptions():
	 * Render and return a well formed Table Options for the creating table.
	 */
	static protected function __renderTableOptions()
	{
		$tableOptions = (string)'';
		if(!is_array(self::$__senchaModel['table'])) return false;
		if( isset(self::$__senchaModel['table']['InnoDB']) ) $tableOptions .= 'ENGINE = '.self::$__senchaModel['table']['InnoDB'].' ';
		if( isset(self::$__senchaModel['table']['autoIncrement']) ) $tableOptions .= 'AUTO_INCREMENT = '.self::$__senchaModel['table']['autoIncrement'].' ';
		if( isset(self::$__senchaModel['table']['charset']) ) $tableOptions .= 'CHARACTER SET = '.self::$__senchaModel['table']['charset'].' ';
		if( isset(self::$__senchaModel['table']['collate']) ) $tableOptions .= 'COLLATE = '.self::$__senchaModel['table']['collate'].' ';
		if( isset(self::$__senchaModel['table']['comment']) ) $tableOptions .= "COMMENT = '".self::$__senchaModel['table']['comment']."' ";
		return $tableOptions;
	}
	 
	/**
	 * function __createAllColumns($paramaters = array()):
	 * This method will create all the columns inside the table of the database
	 * method used by SechaModel method
	 */
	static protected function __createAllColumns($parameters = array())
	{
		try
		{
			foreach($parameters as $column) self::__createColumn($column);
            return true;
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}
	
	/**
	 * function __createColumn($column = array()):
	 * Method that will create a single column into the table
	 */
	static protected function __createColumn($column = array(), $table = NULL)
	{
		try
		{
            if(!$table) $table = (string)(is_array(self::$__senchaModel['table']) ? self::$__senchaModel['table']['name'] : self::$__senchaModel['table']);
			if(self::__rendercolumnsyntax($column) == true) self::$__conn->query('alter table '.$table.' add '.$column['name'].' '.self::__rendercolumnsyntax($column).';');
            return true;
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}		
	}
	
	/**
	 * function __modifyColumn($SingleParamater = array()):
	 * Method to modify a single column properties
	 */
	static protected function __modifyColumn($column = array(), $table = NULL)
	{
		try
		{
            if(!$table) $table = (string)(is_array(self::$__senchaModel['table']) ? self::$__senchaModel['table']['name'] : self::$__senchaModel['table']);
            if(self::__rendercolumnsyntax($column) == true) self::$__conn->query('ALTER TABLE '.$table.' MODIFY '.$column['name'].' '.self::__renderColumnSyntax($column).';');
            return true;
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}
	
	/**
	 * function createDatabase($databaseName):
	 * Method that will create a database, but will create it if
	 * it does not exist.
	 */
	static protected function __createDatabase($databaseName)
	{
		try
		{
			self::$__conn->query('CREATE DATABASE IF NOT EXISTS '.$databaseName.';');
            return true;
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}
	
	/**
	 * function __dropColumn($column):
	 * Method to drop column in a table
	 */
	static protected function __dropColumn($column, $table = NULL)
	{
		try
		{
			if(!$table) $table = (string)(is_array(self::$__senchaModel['table']) ? self::$__senchaModel['table']['name'] : self::$__senchaModel['table']);
			self::$__conn->query("ALTER TABLE ".$table." DROP COLUMN `".$column."`;");
            return true;
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}
	
	/**
	 * function __renderColumnSyntax($column = array()):
	 * Method that will render the correct syntax for the addition or modification
	 * of a column.
	 */
	static protected function __renderColumnSyntax($column = array())
	{
        try
        {
            // parse some properties on Sencha model.
            // and do the defaults if properties are not set.
            if(isset($column['dataType'])): $columnType = (string)strtoupper($column['dataType']);
            elseif($column['type'] == 'string' ): $columnType = (string)'VARCHAR';
            elseif($column['type'] == 'int'):
                $columnType = (string)'INT';
                $column['len'] = (isset($column['len']) ? $column['len'] : 11);
            elseif($column['type'] == 'bool' || $column['type'] == 'boolean'):
                $columnType = (string)'TINYINT';
                $column['len'] = (isset($column['len']) ? $column['len'] : 1);
            elseif($column['type'] == 'date'): $columnType = (string)'DATETIME';
            elseif($column['type'] == 'float'): $columnType = (string)'FLOAT';
            else: return false;
            endif;

            // render the rest of the sql statement
            switch ($columnType)
            {
                case 'BIT'; case 'TINYINT'; case 'SMALLINT'; case 'MEDIUMINT'; case 'INT'; case 'INTEGER'; case 'BIGINT':
                    return $columnType.
                    ( isset($column['len']) ? ($column['len'] ? '('.$column['len'].') ' : '') : '').
                    ( isset($column['defaultValue']) ? (is_numeric($column['defaultValue']) && is_string($column['defaultValue']) ? "DEFAULT '".$column['defaultValue']."' " : '') : '').
                    ( isset($column['comment']) ? ($column['comment'] ? "COMMENT '".$column['comment']."' " : '') : '' ).
                    ( isset($column['allowNull']) ? ($column['allowNull'] ? 'NOT NULL ' : '') : '' ).
                    ( isset($column['autoIncrement']) ? ($column['autoIncrement'] ? 'AUTO_INCREMENT ' : '') : '' ).
                    ( isset($column['primaryKey']) ? ($column['primaryKey'] ? 'PRIMARY KEY ' : '') : '' );
                    break;
                case 'REAL'; case 'DOUBLE'; case 'FLOAT'; case 'DECIMAL'; case 'NUMERIC':
                    return $columnType.
                    ( isset($column['len']) ? ($column['len'] ? '('.$column['len'].')' : '(10,2)') : '(10,2)').
                    ( isset($column['defaultValue']) ? (is_numeric($column['defaultValue']) && is_string($column['defaultValue']) ? "DEFAULT '".$column['defaultValue']."' " : '') : '').
                    ( isset($column['comment']) ? ($column['comment'] ? "COMMENT '".$column['comment']."' " : '') : '' ).
                    ( isset($column['allowNull']) ? ($column['allowNull'] ? 'NOT NULL ' : '') : '' ).
                    ( isset($column['autoIncrement']) ? ($column['autoIncrement'] ? 'AUTO_INCREMENT ' : '') : '' ).
                    ( isset($column['primaryKey']) ? ($column['primaryKey'] ? 'PRIMARY KEY ' : '') : '' );
                    break;
                case 'DATE'; case 'TIME'; case 'TIMESTAMP'; case 'DATETIME'; case 'YEAR':
                    return $columnType.' '.
                    ( isset($column['defaultValue']) ? (is_numeric($column['defaultValue']) && is_string($column['defaultValue']) ? "DEFAULT '".$column['defaultValue']."' " : '') : '').
                    ( isset($column['comment']) ? ($column['comment'] ? "COMMENT '".$column['comment']."' " : '') : '' ).
                    ( isset($column['allowNull']) ? ($column['allowNull'] ? 'NOT NULL ' : '') : '' );
                    break;
                case 'CHAR'; case 'VARCHAR':
                    return $columnType.' '.
                    ( isset($column['len']) ? ($column['len'] ? '('.$column['len'].') ' : '(255)') : '(255)').
                    ( isset($column['defaultValue']) ? (is_numeric($column['defaultValue']) && is_string($column['defaultValue']) ? "DEFAULT '".$column['defaultValue']."' " : '') : '').
                    ( isset($column['comment']) ? ($column['comment'] ? "COMMENT '".$column['comment']."' " : '') : '' ).
                    ( isset($column['allowNull']) ? ($column['allowNull'] ? 'NOT NULL ' : '') : '' );
                    break;
                case 'BINARY'; case 'VARBINARY':
                    return $columnType.' '.
                    ( isset($column['len']) ? ($column['len'] ? '('.$column['len'].') ' : '') : '').
                    ( isset($column['allowNull']) ? ($column['allowNull'] ? '' : 'NOT NULL ') : '' ).
                    ( isset($column['comment']) ? ($column['comment'] ? "COMMENT '".$column['comment']."'" : '') : '' );
                    break;
                case 'TINYBLOB'; case 'BLOB'; case 'MEDIUMBLOB'; case 'LONGBLOB'; case 'TINYTEXT'; case 'TEXT'; case 'MEDIUMTEXT'; case 'LONGTEXT':
                    return $columnType.' '.
                    ( isset($column['allowNull']) ? ($column['allowNull'] ? 'NOT NULL ' : '') : '' ).
                    ( isset($column['comment']) ? ($column['comment'] ? "COMMENT '".$column['comment']."'" : '') : '' );
                    break;
                default:
                    throw new Exception('No data type is defined.');
                    break;
            }
        }
        catch(Exception $e)
        {
            MatchaErrorHandler::__errorProcess($e);
            return false;
        }
	}
	
	/**
	 * function __recursiveArraySearch($needle,$haystack):
	 * An recursive array search method
	 */
	static protected function __recursiveArraySearch($needle,$haystack)
	{
	    foreach($haystack as $key=>$value) 
	    {
	        $current_key=$key;
	        if($needle===$value OR (is_array($value) && self::__recursiveArraySearch($needle,$value) !== false)) return $current_key;
	    }
	    return false;
	}
	
}
