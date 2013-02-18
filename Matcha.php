<?php
/**
 * Matcha::connect microORM v0.0.1
 * This set of classes will help Sencha ExtJS and PHP developers deliver fast and powerful application fast and easy to develop.
 * If Sencha ExtJS is a GUI Framework of the future, think Matcha micrORM as the bridge between the Client-Server
 * GAP. 
 * 
 * Matcha will read and parse a Sencha Model .js file and then connect to the database and produce a compatible database-table
 * from your model. Also will provide the basic functions for the CRUD. If you are familiar with Sencha ExtJS, and know 
 * about Sencha Models, you will need this PHP Class. You can use it in any way you want, in MVC like pattern, your own pattern, 
 * or just playing simple. It's compatible with all your coding stile. 
 * 
 * Taking some ideas from diferent microORM's and full featured ORM's we bring you this super Class. 
 * 
 * History:
 * Born in the fields of GaiaEHR we needed a way to develop the application more faster, Gino Rivera suggested the use of an
 * microORM for fast development, and the development began. We tried to use some already developed and well known ORM on the 
 * space of PHP, but none satisfied our purposes. So Gino Rivera sugested the development of our own microORM (a long way to run).
 * 
 * But despite the long run, it returned to be more logical to get ideas from the well known ORM's and how Sensha manage their models
 * so this is the result. 
 *  
 */


include_once('MatchaAudit.php');
include_once('MatchaCUP.php');
include_once('MatchaErrorHandler.php');

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
	public static $__senchaModel;
	public static $__conn;
	public static $__root;
	public static $__audit;
	
	
	static public function connect($databaseParameters = array())
	{
		try
		{		
			// check for properties first.
			if(!isset($databaseParameters['host']) && 
				!isset($databaseParameters['name']) &&
				!isset($databaseParameters['user']) && 
				!isset($databaseParameters['pass']) &&
				!isset($databaseParameters['root'])) 
				throw new Exception('These parameters are obligatory: host=database ip or hostname, name=database name, user=database username, pass=database password, root=root path of you application.');
				
			// Connect using regular PDO Matcha::setup Abstraction layer.
			// but make only a connection, not to the database.
			// and then the database
			self::$__root = $databaseParameters['root'];
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
	  * function connect($databaseObject, $rootPath, $senchaModel)
	  * The first thing to do, to begin using Matcha
	  * This will load the Sencha Model to Matcha and do it's magic.
	  */
	 static public function setSenchaModel($senchaModel = array())
	 {
	 	try
	 	{
	 		if(self::__SenchaModel($senchaModel))
			{
				$matcha = new MatchaCUP();
				return $matcha;
			}
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
	
	static public function audit($onoff = true)
	{
		self::$__audit = (bool)$onoff;
	}
	
	/**
	 * function SenchaModel($fileModel): 
	 * This method will create the table and fields if does not exist in the database
	 * also this is the brain of the micro ORM.
	 */
	static private function __SenchaModel($fileModel)
	{
		// skip this entire routine if freeze option is true
		if(self::$__freeze) return true;
		try
		{
			// get the the model of the table from the sencha .js file
			self::$__senchaModel = self::__getSenchaModel($fileModel);
			if(!self::$__senchaModel['fields']) throw new Exception('There are no fields set.');
		
			// verify the existence of the table if it does not exist create it
			$recordSet = self::$__conn->query("SHOW TABLES LIKE '".self::$__senchaModel['table']."';");
			if( isset($recordSet) ) self::__createTable(self::$__senchaModel['table']);
			
			// Remove from the model those fields that are not meant to be stored
			// on the database and remove the id from the workingModel.
			$workingModel = (array)self::$__senchaModel['fields'];
			unset($workingModel[self::__recursiveArraySearch('id', $workingModel)]);
			foreach($workingModel as $key => $SenchaModel) if(isset($SenchaModel['store']) && $SenchaModel['store'] == false) unset($workingModel[$key]); 
			
			// get the table column information and remove the id column
			$recordSet = self::$__conn->query("SHOW FULL COLUMNS IN ".self::$__senchaModel['table'].";");
			$tableColumns = $recordSet->fetchAll(PDO::FETCH_ASSOC);
			unset($tableColumns[self::__recursiveArraySearch('id', $tableColumns)]);
			
			// check if the table has columns, if not create them.
			// we start with 1 because the microORM always create the id.
			if( count($tableColumns) <= 1 ) 
			{
				self::__createAllColumns($workingModel);
				return true;
			}
			// Also check if there is difference between the model and the 
			// database table in terms of number of fields.
			elseif(count($workingModel) != (count($tableColumns)))
			{
				// remove columns from the table
				foreach($tableColumns as $column) if( !is_numeric(self::__recursiveArraySearch($column['Field'], $workingModel)) ) self::__dropColumn($column['Field']);
				// add columns to the table
				foreach($workingModel as $column) if( !is_numeric(self::__recursiveArraySearch($column['name'], $tableColumns)) ) self::__createColumn($column);
			}
			// if everything else passes check for differences in the columns.
			else
			{
				// Verify changes in the table 
				// modify the table columns if is not equal to the Sencha Model
				foreach($tableColumns as $column)
				{
					$change = 'false';
					foreach($workingModel as $SenchaModel)
					{
						// if the field is found, start the comparison
						if($SenchaModel['name'] == $column['Field'])
						{
							// the following code will check if there is a dataType property if not, take the type instead 
							// on the model and parse it too.
							$modelDataType = (isset($SenchaModel['dataType']) ? $SenchaModel['dataType'] : $SenchaModel['type']);
							if($modelDataType == 'string') $modelDataType = 'varchar';
							if($modelDataType == 'bool' && $modelDataType == 'boolean') $modelDataType = 'tinyint';
							
							// check for changes on the field type is a obligatory thing
							if(strripos($column['Type'], $modelDataType) === false) $change = 'true'; // Type 
							
							// check if there changes on the allowNull property, 
							// but first check if it's used on the sencha model
							if(isset($SenchaModel['allowNull'])) if( $column['Null'] == ($SenchaModel['allowNull'] ? 'YES' : 'NO') ) $change = 'true'; // NULL
							
							// check the length of the field, 
							// but first check if it's used on the sencha model.
							if(isset($SenchaModel['len'])) if($SenchaModel['len'] != filter_var($column['Type'], FILTER_SANITIZE_NUMBER_INT)) $change = 'true'; // Length
							
							// check if the default value is changed on the model,
							// but first check if it's used on the sencha model
							if(isset($SenchaModel['defaultValue'])) if($column['Default'] != $SenchaModel['defaultValue']) $change = 'true'; // Default value
							
							// check if the primary key is changed on the model,
							// but first check if the primary key is used on the sencha model.
							if(isset($SenchaModel['primaryKey'])) if($column['Key'] != ($SenchaModel['primaryKey'] ? 'PRI' : '') ) $change = 'true'; // Primary key
							
							// check if the auto increment is changed on the model,
							// but first check if the auto increment is used on the sencha model.
							if(isset($SenchaModel['autoIncrement'])) if($column['Extra'] != ($SenchaModel['autoIncrement'] ? 'auto_increment' : '') ) $change = 'true'; // auto increment
							
							// check if the comment is changed on the model,
							// but first check if the comment is used on the sencha model.
							if(isset($SenchaModel['comment'])) if($column['Comment'] != $SenchaModel['comment']) $change = 'true';
							
							// Modify the column on the database							
							if($change == 'true') self::__modifyColumn($SenchaModel);
						}
					}
				}
			}
			return true;
		}
		catch(PDOException $e)
		{
			MatchaErrorHandler::__errorProcess($e);
			return false;
		}
	}
	
	/**
	 * __getSenchaModel($fileModel):
	 * This method is used by SechaModel method to get all the table and column
	 * information inside the Sencha Model .js file 
	 */
	static private function __getSenchaModel($fileModel)
	{
		try
		{
			// Getting Sencha model as a namespace
			$fileModel = (string)str_replace('App', 'app', $fileModel);
			$fileModel = str_replace('.', '/', $fileModel);
			if(!file_exists(self::$__root.'/'.$fileModel.'.js')) throw new Exception('Sencha Model file does not exist.');
			$senchaModel = (string)file_get_contents(self::$__root.'/'.$fileModel.'.js');
			
			// clean comments and unnecessary Ext.define functions
			$senchaModel = preg_replace("((/\*(.|\n)*?\*/|//(.*))|([ ](?=(?:[^\'\"]|\'[^\'\"]*\')*$)|\t|\n|\r))", '', $senchaModel);
			$senchaModel = preg_replace("(Ext.define\('[A-Za-z0-9.]*',|\);|\"|proxy(.|\n)*},)", '', $senchaModel); 
			// wrap with double quotes to all the properties
			$senchaModel = preg_replace('/(,|\{)(\w*):/', "$1\"$2\":", $senchaModel);
			// wrap with double quotes float numbers
			$senchaModel = preg_replace("/([0-9]+\.[0-9]+)/", "\"$1\"", $senchaModel);
			// replace single quotes for double quotes
			// TODO: refine this to make sure doesn't replace apostrophes used in comments. example: don't
			$senchaModel = preg_replace("(')", '"', $senchaModel);
			
			$model = (array)json_decode($senchaModel, true);
			if(!count($model)) throw new Exception("Ops something whent wrong converting it to an array.");
			
			// get the table from the model
			if(!isset($model['table'])) throw new Exception("Table property is not defined on Sencha Model. 'table:'");

			if(!isset($model['fields'])) throw new Exception("Fields property is not defined on Sencha Model. 'fields:'");
			return $model;
		}
		catch(Exception $e)
		{
			MatchaErrorHandler::__errorProcess($e);
			return false;
		}
	}

	/**
	 * function __getRelationFromModel():
	 * Method to get the relation from the model if has any
	 */
	static private function __getRelationFromModel()
	{
		try
		{
			// first check if the sencha model object has some value
			self::$Relation = 'none';
			if(isset(self::$__senchaModel)) throw new Exception("Sencha Model is not configured.");
			
			// check if the model has the associations property 
			if(isset(self::$__senchaModel['associations']))
			{
				self::$Relation = 'associations';
				// load all the models.
				foreach(self::$__senchaModel['associations'] as $relation)
				{ 
					self::SenchaModel(self::$__senchaModel['associations']);
					self::$RelationStatement[] = self::__leftJoin(
					array(
						'fromId'=>(isset(self::$__senchaModel['associations']['primaryKey']) ? self::$__senchaModel['associations']['foreignKey'] : 'id'),
						'toId'=>self::$__senchaModel['associations']['foreignKey']
					));
				}
			}
			
			// check if the model has the associations property 
			if(isset(self::$__senchaModel['hasOne']))
			{
				self::$Relation = 'hasOne';
				self::$RelationStatement[] = self::__leftJoin(
				array(
					'fromId'=>(isset(self::$__senchaModel['associations']['primaryKey']) ? self::$__senchaModel['associations']['foreignKey'] : 'id'),
					'toId'=>self::$__senchaModel['associations']['foreignKey']
				));
			}
			
			// check if the model has the associations property 
			if(isset(self::$__senchaModel['hasMany']))
			{
				self::$Relation = 'hasMany';
				self::$RelationStatement[] = self::__leftJoin(
				array(
					'fromId'=>(isset(self::$__senchaModel['associations']['primaryKey']) ? self::$__senchaModel['associations']['foreignKey'] : 'id'),
					'toId'=>self::$__senchaModel['associations']['foreignKey']
				));
			}
			
			// check if the model has the associations property 
			if(isset(self::$__senchaModel['belongsTo']))
			{
				self::$Relation = 'belongsTo';
				self::$RelationStatement[] = self::__leftJoin(
				array(
					'fromId'=>(isset(self::$__senchaModel['associations']['primaryKey']) ? self::$__senchaModel['associations']['foreignKey'] : 'id'),
					'toId'=>self::$__senchaModel['associations']['foreignKey']
				));
			}
			
			return true;
		}
		catch(Exception $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}

	/**
	 * function __leftJoin($joinParameters = array()):
	 * A left join returns all the records in the “left” table (T1) whether they 
	 * have a match in the right table or not. If, however, they do have a match 
	 * in the right table – give me the “matching” data from the right table as well. 
	 * If not – fill in the holes with null.
	 */
	static private function __leftJoin($joinParameters = array())
	{
		return (string)' LEFT JOIN ' . $joinParameters['relateTable'].' ON ('.self::$__senchaModel['table'].'.'.$joinParameters['fromId'].' = '.$joinParameters['relateTable'].'.'.$joinParameters['toId'].') ';
	}
	
	/**
	 * function __innerJoin($joinParameters = array()):
	 * An inner join only returns those records that have “matches” in both tables. 
	 * So for every record returned in T1 – you will also get the record linked by 
	 * the foreign key in T2. In programming logic – think in terms of AND.
	 */
	static private function __innerJoin($joinParameters = array())
	{
		return (string)' INNER JOIN ' . $joinParameters['relateTable'].' ON ('.self::$__senchaModel['table'].'.'.$joinParameters['fromId'].' = '.$joinParameters['relateTable'].'.'.$joinParameters['toId'].') ';
	}

	/**
	 * function __setSenchaModel($senchaModelObject):
	 * Set the Sencha Model by an object
	 * Useful to pass the model via an object, instead of using the .js file
	 * it can be constructed dynamically.
	 * TODO: Finish me!
	 */
	static private function __setSenchaModel($senchaModelObject)
	{
		
	}
	
	/**
	 * function __createTable():
	 * Method to create a table if does not exist
	 */
	 static private function __createTable()
	 {
	 	try
	 	{
			self::$__conn->query('CREATE TABLE IF NOT EXISTS '.self::$__senchaModel['table'].' (id BIGINT(20) NOT NULL AUTO_INCREMENT PRIMARY KEY);');
			return true;
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	 }
	 
	/**
	 * function __createAllColumns($paramaters = array()):
	 * This method will create the column inside the table of the database
	 * method used by SechaModel method
	 */
	static private function __createAllColumns($paramaters = array())
	{
		try
		{
			foreach($paramaters as $column) self::__createColumn($column);
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}
	}
	
	/**
	 * function __createColumn($column = array()):
	 * Method that will create the column into the table
	 */
	static private function __createColumn($column = array())
	{
		try
		{
			self::$__conn->query('ALTER TABLE '.self::$__senchaModel['table'].' ADD '.$column['name'].' '.self::__renderColumnSyntax($column) . ';');
		}
		catch(PDOException $e)
		{
			return MatchaErrorHandler::__errorProcess($e);
		}		
	}
	
	/**
	 * function __modifyColumn($SingleParamater = array()):
	 * Method to modify the column properties
	 */
	static private function __modifyColumn($SingleParamater = array())
	{
		try
		{
			self::$__conn->query('ALTER TABLE '.self::$__senchaModel['table'].' MODIFY '.$SingleParamater['name'].' '.self::__renderColumnSyntax($SingleParamater) . ';');
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
	static private function __createDatabase($databaseName)
	{
		try
		{
			self::$__conn->query('CREATE DATABASE IF NOT EXISTS '.$databaseName.';');
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
	static private function __dropColumn($column)
	{
		try
		{
			self::$__conn->query("ALTER TABLE ".self::$__senchaModel['table']." DROP COLUMN `".$column."`;");
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
	static private function __renderColumnSyntax($column = array())
	{
		// parse some properties on Sencha model.
		// and do the defaults if properties are not set.
		$columnType = (string)'';
		if(isset($column['dataType'])) 
		{
			$columnType = strtoupper($column['dataType']);
		}
		elseif($column['type'] == 'string' )
		{
			$columnType = 'VARCHAR';
		}
		elseif($column['type'] == 'int')
		{
			$columnType = 'INT';
			$column['len'] = (isset($column['len']) ? $column['len'] : 11);
		}
		elseif($column['type'] == 'bool' || $column['type'] == 'boolean')
		{
			$columnType = 'TINYINT';
			$column['len'] = (isset($column['len']) ? $column['len'] : 1);
		}
		elseif($column['type'] == 'date')
		{
			$columnType = 'DATE';
		}
		elseif($column['type'] == 'float')
		{
			$columnType = 'FLOAT';
		}
		else
		{
			return false;
		}
		
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
		}
		return true;
	}
	
	/**
	 * __recursiveArraySearch($needle,$haystack):
	 * An recursive array search method
	 */
	static private function __recursiveArraySearch($needle,$haystack) 
	{
	    foreach($haystack as $key=>$value) 
	    {
	        $current_key=$key;
	        if($needle===$value OR (is_array($value) && self::__recursiveArraySearch($needle,$value) !== false)) return $current_key;
	    }
	    return false;
	}
	
}