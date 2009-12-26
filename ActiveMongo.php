<?php
/**
 * ActiveMongo
 * ActiveRecord for MongoDB
 * 
 * @name        ActiveMongo
 * @package     ActiveMongo
 * @version     0.1-$Id$
 * @link        http://github.com/flabs/ActiveMongo
 * @author      Dennis Morhardt <info@dennismorhardt.de>
 *
 * Copyright (c) 2010 Dennis Morhardt <info@dennismorhardt.de>
 *
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation
 * files (the "Software"), to deal in the Software without
 * restriction, including without limitation the rights to use,
 * copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the
 * Software is furnished to do so, subject to the following
 * conditions:
 *
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES
 * OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT
 * HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY,
 * WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR
 * OTHER DEALINGS IN THE SOFTWARE.
 */
 
class ActiveMongo_Exception extends Exception {}

class ActiveMongo {
	private static $__mongo = null;
	private static $__connection = null;
	private $__data = array();
	private $__saved = true;
	private $__new = false;
	private $__collection;
	private $__valid = null;
	static $fields = array();
	static $required = array();
	static $defaults = array();

	public function __construct($data = array(), $new = true, $saved = false) {
		// Set the data
		if ( true == $new )
			foreach( $data as $parameter => $value ) $this->__set($parameter, $value);
		else
			$this->__data = $data;
	
		// Set the states
		$this->__saved = $saved;
		$this->__new = $new;
		
		// Name of the collection
		$this->__collection = $this->format_collection_name(get_called_class());
	}
	
	public function __get($parameter) {
		// Translate 'id' to '_id'
		if ( 'id' == $parameter )
			$parameter = '_id';
			
		// Load the value
		if ( '_id' == $parameter && false == isset( $this->__data['_id'] ) )
			$value = $this->__set('_id', new MongoId());
		elseif ( isset( $this->__data[$parameter] ) )
			$value = $this->__data[$parameter];
		else
			return null;
		
		// Transform MongoId to a string
		if ( $value instanceof MongoId )
			$value = $value->__toString();
		
		// Return the timestamp instand of a MongoDate object
		if ( $value instanceof MongoDate )
			$value = $value->sec;
			
		// Run getter
		if ( '_id' == $parameter && method_exists( $this, "get_id" ) )
			$value = $this->get_id($value);
		elseif ( method_exists( $this, $getter = "get_{$parameter}" ) )
			$value = $this->$getter($value);
			
		// Return null if parameter has not been found
		return $value;
	}
	
	public function __set($parameter, $value) {
		// Transform 'id' to '_id' and format it
		if ( 'id' == $parameter ):
			$parameter = '_id';
			$value = $this->format_id($value);
		endif;
		
		// Whitelist parameters
		if ( 0 !== count( (array)static::$fields ) ):
			$whitelist = array_merge(static::$fields, array('_id', 'created_at', 'updated_at'));
			if ( false == in_array( $parameter, $whitelist ) )
				throw new ActiveMongo_Exception("The parameter '{$parameter}' is not allowed.");
		endif;
		
		// Run setter
		if ( '_id' == $parameter && method_exists( $this, "set_id" ) )
			$value = $this->set_id($value);
		elseif ( method_exists( $this, $setter = "set_{$parameter}" ) )
			$value = $this->$setter($value);
		
		// Set the saved state to false and save the parameter
		$this->__saved = false;
		$this->__data[$parameter] = $value;
		
		// Return the value
		return $value;
	}
	
	public function __isset($parameter) {
		// Translate 'id' to '_id' and check if it exists
		if ( 'id' == $parameter && isset( $this->__data['_id'] ) )
			return true;
			
		// Check if the parameter exists
		if ( isset( $this->__data[$parameter] ) )
			return true;

		// Return false if the parameter has not been found
		return false;
	}
	
	public static function __callStatic($method, $args) {
		// Init the options
		if ( false == is_array( $options = $args[1] ) )
			$options = array();			
	
		// Find by...
		if ( 'find_by' === substr( $method, 0, 7) ):
			$options['conditions'] = array_merge((array)$options['conditions'], array(substr($method, 8) => $args[0]));
			return static::find('first', $options);
		// Find all by...
		elseif ( 'find_all_by' === substr( $method, 0, 11 ) ):
			$options['conditions'] = array_merge((array)$options['conditions'], array(substr($method, 12) => $args[0]));
			return static::find('all', $options);
		endif;
	}
	
	public function get($parameter) {
		// Alias for __get()
		$this->__get($parameter);
	}
	
	public function set($parameter, $value) {
		// Alias for __set()
		$this->__set($parameter, $value);
	}
	
	public function connect($collection = null) {
		// Don't call the parent class directly
		if ( 'ActiveMongo' == get_called_class() )
			throw new ActiveMongo_Exception("Please don't call ActiveMongo directly.");
	
		// Check if the mongo extension is installed
		if ( false == extension_loaded( 'mongo' ) )
			throw new ActiveMongo_Exception('Please install the mongo extension for PHP.');
	
		// Get the collection
		if ( null == $collection )
			$collection = self::format_collection_name(get_called_class());
			
		// Get connection details
		$details = self::parse_dsn_string();

		// Connect
		if ( false == is_object( self::$__mongo ) ):
			try {
				self::$__mongo = new Mongo($details->dsn);
			} catch( MongoConnectionException $e ) {
				throw new ActiveMongo_Exception('Could not connect to the mongo database, error message: ' . $e->getMessage());
			}
		endif;
		
		// Select the collection
		self::$__connection = self::$__mongo->selectCollection($details->db, $collection);
	}
	
	public static function find($ids_or_type = 'all', $options = array()) {
		// Connect if we not connected
		self::connect();

		// Init the options
		$defaults = array('fields' => array(), 'conditions' => array(), 'limit' => false, 'offset' => false, 'sort' => array());
		$options = array_merge($defaults, $options);
		
		// If a ID has been requested pass the find to find_by_id()
		if ( false == in_array( $ids_or_type, array( 'all', 'first', 'last' ) ) )
			return self::find_by_id($ids_or_type, $options['fields']);
			
		// Init
		$query = self::$__connection->find($options['conditions'], $options['fields']);
		
		// Sort
		if ( 'first' == $ids_or_type )
			$query->sort(array_merge($options['sort'], array('created_at' => 1)));
		elseif ( 'last' == $ids_or_type )
			$query->sort(array_merge($options['sort'], array('created_at' => -1)));
		else
			$query->sort($options['sort']);
			
		// Limit
		if ( ( 'first' == $ids_or_type || 'last' == $ids_or_type ) && false == $options['limit'] )
			$query->limit($options['limit'] = 1);
		elseif ( true == $options['limit'] )
			$query->limit($options['limit']);
			
		// Offset
		if ( true == $options['offset'] )
			$query->skip($options['offset']);
			
		// Return the transformated query
		return self::transform($query, $options);
	}
	
	public static function all($options = array()) {
		// Alias for find::('all'[, array $options = array()])
		return static::find('all', $options);
	}
	
	public static function last($options = array()) {
		// Alias for find::('last'[, array $options = array()])
		return static::find('last', $options);
	}
	
	public static function first($options = array()) {
		// Alias for find::('first'[, array $options = array()])
		return static::find('first', $options);
	}
	
	public static function find_by_id($id, $fields = array()) {
		// Connect if we not connected
		self::connect();
		
		// Format the ID
		$id = self::format_id($id);
		
		// Excute the query
		$query = self::$__connection->find(array('_id' => $id), $fields);
		
		// Return the transformated query
		return self::transform($query, array('limit' => '1'));
	}
	
	public static function destroy($conditions, $just_one = false) {
		// Connect if we not connected
		self::connect();
		
		// Remove the objects from the collection
		return self::$__connection->remove($conditions, $just_one);
	}
	
	public static function drop() {
		// Connect if we not connected
		self::connect();
		
		// Drop the collection
		try {
			self::$__connection->drop();
		} catch( MongoCursorException $e ) {
			throw new ActiveMongo_Exception('Could not drop the collection: ' . $e->getMessage());
		}
	}
	
	public function get_values() {
		// Init
		$data = array();
		
		// Format the data
		foreach ( $this->__data as $parameter => $value ):
			if ( '_id' == $parameter ) $parameter = 'id';
			$data[$parameter] = $this->__get($parameter);
		endforeach;
	
		// Get the raw data
		return $data;
	}
	
	public function is_new_record() {
		// Return if the record is new
		return $this->__new;
	}
	
	public function is_saved() {
		// Return if the record has been saved
		return $this->__saved;
	}
	
	public function is_valid() {
		// Check only if it was already checked
		if ( null === $this->__valid )
			return $this->validate();
		
		// Everything fine
		return true;
	}
	
	public function validate() {
		// Check only if we have a list of required parameters
		if ( 0 !== count( (array)static::$required ) ):
			foreach ( static::$required as $required ):
				if ( false == in_array( $required, array_keys($this->__data) ) ) return ( $this->__valid = false );
			endforeach;
		endif;
		
		// Everything fine
		return ( $this->__valid = true );
	}
	
	public function to_json() {
		// Return this model as json
		return json_encode($this->get_values());
	}
	
	public function delete() {
		// Connect if we not connected
		self::connect($this->__collection);
		
		// Run callbacks
		$this->run_callbacks('before_delete');
		
		// Delete the item
		$state = self::$__connection->remove(array('_id' => $this->__data['_id']));
		
		// Destory the model
		if ( true == $state):
			$this->__saved = true;
			$this->__new = true;
			$this->__data = array();
		endif;
		
		// Run callbacks
		$this->run_callbacks('after_delete');
		
		return $state;
	}
	
	public function save($validate = true) {
		// Connect if we not connected
		self::connect($this->__collection);
		
		// Model is already saved
		if ( true == $this->__saved )
			return true;
			
		// Set defaults
		$this->set_defaults();
			
		// Check if all fields has been filled
		if ( true == $validate && false == $this->validate() )
			throw new ActiveMongo_Exception('Not all required fields has been filled.');
			
		// Run callbacks
		$this->run_callbacks('before_save');
		
		// Update or create?
		if ( false == $this->__new )
			$data = $this->update();
		else
			$data = $this->create();
		
		// Check if nothing failed
		if ( false == $data )
			throw new ActiveMongo_Exception('Updating or creating of record has failed.');
			
		// Set the state of the model
		$this->__saved = true;
		$this->__new = false;
		
		// Run callbacks
		$this->run_callbacks('after_save');
		
		// Everything fine.
		return true;
	}
	
	private function update() {
		// Connect if we not connected
		self::connect($this->__collection);
		
		// Run callbacks
		$this->run_callbacks('before_update');
		
		// Set 'updated_at'
		$this->__data['updated_at'] = new MongoDate();
		
		// Update
		$result = self::$__connection->update(array('_id' => $this->__data['_id']), $this->__data);
		
		// Run callbacks
		$this->run_callbacks('after_update');
		
		// Return the result
		return $result;
	}
	
	private function create() {
		// Connect if we not connected
		self::connect($this->__collection);
		
		// Run callbacks
		$this->run_callbacks('before_create');
		
		// Set 'updated_at' & 'created_at'
		$this->__data['updated_at'] = new MongoDate();
		$this->__data['created_at'] = new MongoDate();
		
		// Insert into the collection
		$result = self::$__connection->insert($this->__data);
		
		// Run callbacks
		$this->run_callbacks('after_create');
		
		// Return the result
		return $result;
	}
	
	private function format_collection_name($string) {
		$uncountable = array('sheep', 'fish', 'deer', 'series', 'species', 'money', 'rice', 'information', 'equipment');
		$irregular = array(
			'move'   => 'moves',
			'foot'   => 'feet',
			'goose'  => 'geese',
			'sex'    => 'sexes',
			'child'  => 'children',
			'man'    => 'men',
			'woman'  => 'women',
			'tooth'  => 'teeth',
			'person' => 'people',
		);
		$plural_rules = array(
			'/(quiz)$/i'               => "$1zes",
			'/^(ox)$/i'                => "$1en",
			'/([m|l])ouse$/i'          => "$1ice",
			'/(matr|vert|ind)ix|ex$/i' => "$1ices",
			'/(x|ch|ss|sh)$/i'         => "$1es",
			'/([^aeiouy]|qu)y$/i'      => "$1ies",
			'/(hive)$/i'               => "$1s",
			'/(?:([^f])fe|([lr])f)$/i' => "$1$2ves",
			'/(shea|lea|loa|thie)f$/i' => "$1ves",
			'/sis$/i'                  => "ses",
			'/([ti])um$/i'             => "$1a",
			'/(tomat|potat|ech|her|vet)o$/i'=> "$1oes",
			'/(bu)s$/i'                => "$1ses",
			'/(alias)$/i'              => "$1es",
			'/(octop)us$/i'            => "$1i",
			'/(ax|test)is$/i'          => "$1es",
			'/(us)$/i'                 => "$1es",
			'/s$/i'                    => "s",
			'/$/'                      => "s"
		);
		
		$string = strtolower($string);
		$string = preg_replace('/[_\- ]+/', '_', trim($string));
	
		if ( in_array( strtolower( $string ), $uncountable ) )
			return $string;

		foreach ( $irregular as $pattern => $result ):
			$pattern = '/' . $pattern . '$/i';

			if ( preg_match( $pattern, $string ) )
				return preg_replace( $pattern, $result, $string);
		endforeach;

		foreach ( $plural_rules as $pattern => $result ):
			if ( preg_match( $pattern, $string ) )
				return preg_replace( $pattern, $result, $string );
		endforeach;

		return $string;
	}
	
	private function set_defaults() {
		// Set only defaults if where some defined
		if ( 0 !== count( (array)static::$defaults ) ):
			foreach ( static::$defaults as $parameter => $default ):
				if ( false == in_array( $parameter, array_keys($this->__data) ) ) $this->__data[$parameter] = $default;
			endforeach;
		endif;
	}
	
	private function format_id($id) {
		// Connect if we not connected
		self::connect();
	
		// Return it if it already a mongo ID
		if ( $id instanceof MongoId )
			return $id;

		// If it is a string transform it to a mongo ID
		if ( is_string( $id ) )
			return new MongoId($id);

		// If it is an array transform it to a mongo ID
		if ( is_array( $id ) && isset( $id['_id'] ) )
			return $this->format_id($id['_id']);
		
		// If it is an object transform it to a mongo ID
		if ( is_object( $id ) && isset( $id->id ) )
			return $this->format_id($id->id);

		// If it can not be determined by format_id() return a fresh mongo id
		return new MongoId();
	}
	
	private function parse_dsn_string() {
		// Check if database information is provided
		if ( false == defined( 'DATABASE' ) )
			throw new ActiveMongo_Exception('No database information provided.');
	
		// Load the settings
		$url = @parse_url(DATABASE);
		$default_port = ini_get('mongo.default_port') ? ini_get('mongo.default_port') : '27017';

		// Check if a host has been provided
		if ( false == isset( $url['host'] ) )
			throw new ActiveMongo_Exception('Database host must be specified in the connection string.');
		
		// Currently only mongo db is supported
		if ( 'mongodb' !== $url['scheme'] )
			throw new ActiveMongo_Exception('Currently only mongo db supported.');
			
		// Set the port
		if ( null == $url['port'] )
			$url['port'] = $default_port;
			
		// Add login data if needed
		if ( isset( $url['user'] ) && isset( $url['pass'] ) )
			$ua = $url['user'] . ':' . $url['pass'] . '@';

		// Create the info object
		$info = new stdClass();
		$info->dsn = 'mongodb://' . $ua . $url['host'] . ':' . $url['port'];
		$info->db = isset( $url['path'] ) ? substr( $url['path'], 1 ) : null;
		
		// No database? Fail.
		if ( false == isset( $info->db ) )
			throw new ActiveMongo_Exception('You need to define a default database.');
			
		return $info;
	}
	
	private function transform($data, $options) {
		// Init
		$_data = array();
		$_class = get_called_class();
	
		// Transform the MongoCursor object to an array
		foreach ( $data as $d )
			$_data[] = new $_class($d, false, true);
			
		// The First element
		$first = $_data[0] ? $_data[0] : false;
		
		// Return the array or only just one object
		return ( '1' == (string)$options['limit'] ) ? $first : $_data;
	}
	
	private function run_callbacks($action) {
		// Try to get load the callbacks
		$reflector = new ReflectionClass(get_called_class());
		$callbacks = $reflector->getStaticPropertyValue($action, array());
	
		// Run only the callbacks if at least one is defined
		if ( 0 !== count( $callbacks ) ):
			foreach ( $callbacks as $function ):
				$this->$function();
			endforeach;
		endif;
	}
}
