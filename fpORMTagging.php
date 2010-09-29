<?php
/**
 * Flourish ORM plugin for tagging
 *
 * Requires:
 *
 * CREATE TABLE tags (
 *    tag VARCHAR(255) PRIMARY KEY
 * );
 *
 * CREATE TABLE tags_related_table (
 *    related_table_id INTEGER NOT NULL references related_table(related_table_id) ON DELETE CASCADE,
 *    tag VARCHAR(255) NOT NULL references tags(tag) ON DELETE RESTRICT ON UPDATE CASCADE,
 *    PRIMARY KEY (tag, related_table_id)
 * );
 *
 * Usage:
 *
 * Add linking table(s) to a tags table (see above tag_related_table for an example).
 *
 * To initialize, call fpORMTagging::configure() in your init file on whichever tagging class you wish.
 * Then you can gatherRelated() or gatherRelatedRandom() on an fActiveRecord or fRecordSet of tags, or any fActiveRecord related to tags
 *
 * @copyright  2010, iMarc <info@imarc.net>
 * @author     Craig Ruks [cr] <craigruk@imarc.net>
 * @author     Will Bond [wb] <will@imarc.net>
 *
 * @package    Flourish Plugins
 *
 * @version    2.0
 * @changes    2.0.0     Methods now abstracted to the fActiveRecord or fRecordSet level of a related record or tag(s).
 * @changes    1.0.0     The initial implementation [cr, 2010-08-13]
 */
class fpORMTagging 
{
	/**
	 * An associative array to cache the tag and its associated classes to
	 * 
	 * @var array
	 */
	static private $configured_classes = array();
	
	/**
	 * An associative array to cache the tag and its column to
	 * 
	 * @var array
	 */
	static private $configured_columns = array();
	
	/**
	 * An associative array to cache the related classes and their ordering methods
	 * 
	 * @var array
	 */
	static private $configured_related_orderings = array();
	
	/**
	 * An array to cache any preset tags that are by default added and cannot be removed
	 * 
	 * @var array
	 */
	static private $configured_preset_tags = array();
	
	/**
	 * An associative array to cache the class and its sorting method to
	 * 
	 * @var array
	 */
	static private $sort_classes;
	
	/**
	 * The direction to sort records when gathering
	 * 
	 * @var string
	 */
	static private $sort_direction;
	
	/**
	 * The method to sort records when gathering
	 * 
	 * @var string
	 */
	static private $sort_methods;
	
	
	/**
	 * Configures tagging for every class related to the tag class passed in
	 *
	 * @param string $class              The name of the tag class
	 * @param string $column             The tag column for the tag class
	 * @param array  $related_orderings  An associative array of Class => getMethod() for each related class
	 * @param array  $preset_tags  Any preset tags that cannot be deleted
	 * @return void
	 */
	static public function configure($class, $column, $related_orderings, $preset_tags=array())
	{
		$class         = fORM::getClass($class);
		$table         = fORM::tablize($class);
		$schema        = fORMSchema::retrieve($class);
		$data_type     = $schema->getColumnInfo($table, $column, 'type');
		$relationships = $schema->getRelationships($table, 'many-to-many');
		
		// configure static settings for this instance
		self::$configured_classes[$class]           = array();
		self::$configured_columns[$class]           = $column;
		self::$configured_related_orderings[$class] = $related_orderings;
		self::$configured_preset_tags[$class]       = $preset_tags;
		
		// add hook to lowercase tag when set
		$set_tag_method = 'set' . fGrammar::camelize($column, TRUE);
		fORM::registerActiveRecordMethod($class, $set_tag_method, __CLASS__ . '::setTag');
		
		// add hooks to all classes that use tags
		foreach ($relationships as $relationship) {
			$related_class = fORM::classize($relationship['related_table']);
			self::$configured_classes[$class][] = $related_class;
			fORM::registerHookCallback($related_class, 'post::populate()', __CLASS__ . '::populateTags');
		}
		
		// insert preset tags
		foreach ($preset_tags as $tag) {
			try {
				$old_tag = new $class($tag);
			} catch (Exception $e) {
				$new_tag = new $class();
				$new_tag->$set_tag_method($tag);
				$new_tag->store();
			}
		}
		
		// add reflect and active record methods for a record related to tags, `gatherRelated` and `gatherRelatedRandom`
		foreach (self::$configured_classes[$class] as $tag_class => $related_class) {
				fORM::registerReflectCallback(
					$related_class,
					__CLASS__ . '::reflectGatherRelated'
				);

				fORM::registerReflectCallback(
					$related_class,
					__CLASS__ . '::reflectGatherRelatedRandom'
				);

				fORM::registerActiveRecordMethod(
					$related_class,
					'gatherRelated',
					__CLASS__ . '::gatherForRecord'
				);

				fORM::registerActiveRecordMethod(
					$related_class,
					'gatherRelatedRandom',
					__CLASS__ . '::gatherForRecord'
				);
		}
		
		// add reflect, active record and record set methods for tag(s), called `gatherRelated` and `gatherRelatedRandom`
		
		fORM::registerRecordSetMethod(
			'gatherRelated',
			__CLASS__ . '::gatherForRecordSet'
		);
		
		fORM::registerRecordSetMethod(
			'gatherRelatedRandom',
			__CLASS__ . '::gatherForRandomRecordSet'
		);
		
		fORM::registerReflectCallback(
			$class,
			__CLASS__ . '::reflectGatherRelated'
		);

		fORM::registerReflectCallback(
			$class,
			__CLASS__ . '::reflectGatherRelatedRandom'
		);
		
		fORM::registerActiveRecordMethod(
			$class,
			'gatherRelated',
			__CLASS__ . '::gatherForRecord'
		);

		fORM::registerActiveRecordMethod(
			$class,
			'gatherRelatedRandom',
			__CLASS__ . '::gatherForRecord'
		);
	}
	
	
	/**
	 * Deletes unused tags, garbage collecting method
	 *
	 * @param string $class  The name of the tag class
	 * @return void
	 */
	static public function deleteDefunctTags($class=NULL)
	{
		if ($class === NULL) {
			$class = self::getDefaultTagClass();
		}
		$class          = fORM::getClass($class);
		$table          = fORM::tablize($class);
		$column         = self::$configured_columns[$class];
		$preset_tags    = self::$configured_preset_tags[$class];
		$get_tag_method = 'get' . fGrammar::camelize($column, TRUE);
		
		$tags = fRecordSet::build($class);
		
		foreach ($tags as $tag) {
			if (in_array($tag->$get_tag_method(), $preset_tags)) {
				continue;
			}
			if ($tag->gatherRelated()->count() == 0) {
				$tag->delete();
			}
		}
	}
	
	
	/**
	 * Gather up records related to tag(s)
	 *
	 * @param mixed         $tags             The tags to filter by (can be string, array, or fRecordSet)
	 * @param integer       $limit            Number of records to return
	 * @param array         $classes          An associative array of classes to return records for, where the key is the class and the value is the sorting method (it is recommended not to mix data types for sorting)
	 * @param string        $sort_direction   Sort order direction
	 * @param string        $tag_class        The tag class to pull from, defaults to 'Tag'
	 * @param fActiveRecord $original_object  The original object to relate to (if not called by a tag)
	 * @param boolean       $random           Whether or not to randomly sort the returned records
	 * @return fRecordSet
	 */
	static private function build($tags, $limit=NULL, $filtering_classes=array(), $sort_direction='asc', $tag_class=NULL, $original_object=NULL, $random=FALSE)
	{
		if ($tag_class === NULL) {
			$tag_class = self::getDefaultTagClass();
		}
		$tag_class  = fORM::getClass($tag_class);
		$tag_table  = fORM::tablize($tag_class);
		$tag_schema = fORMSchema::retrieve($tag_class);
		$tag_column = self::$configured_columns[$tag_class];
		
		if ($tags instanceOf fRecordSet) {
			$tags = $tags->getRecords();
		}
		
		if (sizeof($tags) == 1 && !is_array($tags)) {
			$tags = preg_split('#\s*,\s*#', $tags);
		}

		// default orderings
		$related_orderings = self::$configured_related_orderings[$tag_class];
		$classes = array_keys($related_orderings);
		$columns = array_values($related_orderings);

		if ($filtering_classes) {
			// if an associative array of Class => column passed in
			$keys = array_keys($filtering_classes);
			if (is_string($keys[0])) {
				$classes = array_keys($filtering_classes);
				$columns = array_values($filtering_classes);
			
			// if an array of classes are passed in determine get methods from defaults
			} else {
				$classes = $filtering_classes;
				$columns = array();
				foreach ($filtering_classes as $class) {
					$columns[] = $related_orderings[$class];
				}
			}
		}
		
		$get_methods = array();
		foreach ($columns as $column) {
			$get_methods[] = 'get' . fGrammar::camelize($column, TRUE);
		}
		
		$set = fRecordSet::buildFromArray($classes, array());
		
		$where_conditions = array($tag_table . '.' . $tag_column . '=' => $tags);
		foreach ($classes as $i => $class) {
			// limit + 1 because object may reduce final size by 1
			$record_set = fRecordSet::build($class, $where_conditions, array($columns[$i] => $sort_direction), ($limit) ? $limit + 1 : NULL);
			
			$set = $set->merge($record_set);
		}
		
		if ($original_object) {
			$set = $set->diff($original_object);
		}
		
		self::$sort_classes   = $classes;
		self::$sort_methods   = $get_methods;
		self::$sort_direction = $sort_direction;
		
		if ($random) {
			$records = $set->getRecords();
			shuffle($records);

			if ($limit > 0) {
				$records = array_slice($records, 0, $limit);
			}

			return fRecordSet::buildFromArray($classes, $records);
		}
		
		$set = $set->sortByCallback('fpORMTagging::sortRecordsCallback');
		if ($limit > 0) {
			return $set->slice(0, $limit);
		}
		return $set;
	}
	
	
	/**
	 * Gather a record set of related records for either a tag or a record
	 * 
	 * @internal
	 * 
	 * @param  fActiveRecord $object            The fActiveRecord instance
	 * @param  array         &$values           The current values
	 * @param  array         &$old_values       The old values
	 * @param  array         &$related_records  Any records related to this record
	 * @param  array         &$cache            The cache array for the record
	 * @param  string        $method_name       The method that was called
	 * @param  array         $parameters        The parameters passed to the method
	 * @return fRecordSet  A set of related records
	 */
	static public function gatherForRecord($object, &$values, &$old_values, &$related_records, &$cache, $called_method_name, $parameters)
	{
		$limit          = (isset($parameters[0])) ? $parameters[0] : NULL;
		$classes        = (isset($parameters[1])) ? $parameters[1] : array();
		$sort_direction = (isset($parameters[2])) ? $parameters[2] : 'asc';
		
		$random = FALSE;
		if (preg_match('#random#i', $called_method_name)) {
			$random = TRUE;
		}
		
		// if the object is a tag
		$tags      = $object;
		$tag_class = get_class($object);
		
		// if the object is a related record
		if (!in_array(get_class($object), array_keys(self::$configured_classes))) {
			$tag_class         = (isset($parameters[3])) ? $parameters[3] : self::getDefaultTagClass();
			$build_tags_method = 'build' . fGrammar::camelize(fGrammar::pluralize($tag_class), TRUE);
			$tags              = $object->$build_tags_method();
		}
		
		return self::build($tags, $limit, $classes, $sort_direction, $tag_class, NULL, $random);
	}
	
	
	/**
	 * Gather a record set of related records for a record set of tags
	 * 
	 * @internal
	 * 
	 * @param  fRecordSet $record_set  The fRecordSet instance
	 * @param  string     $class       The class of the records
	 * @param  array      &$records    The fActiveRecord objects
	 * @param  integer    &$pointer    The current iteration pointer
	 * @return fRecordSet  A set of related records
	 */
	static public function gatherForRecordSet($object, $class, &$records, &$pointer, $parameters)
	{
		$limit          = (isset($parameters[0])) ? $parameters[0] : NULL;
		$classes        = (isset($parameters[1])) ? $parameters[1] : array();
		$sort_direction = (isset($parameters[2])) ? $parameters[2] : 'asc';
		$tag_class      = (isset($parameters[3])) ? $parameters[3] : self::getDefaultTagClass();
		
		if (!in_array($class, array_keys(self::$configured_classes))) {
			throw new fProgrammerException(
				'The method, %1$s, was called on a record set of %2$s objects, however %3$s has not been configured as a tag class. Valid tag classes include: %4$s.',
				'gatherRelated()',
				$class,
				$class,
				join(', ', array_keys(self::$configured_classes))
			);
		}
		
		return self::build($object, $limit, $classes, $sort_direction, $tag_class, NULL, FALSE);
	}
	
	
	/**
	 * Gather a randomly sorted record set of related records for a record set of tags
	 * 
	 * @internal
	 * 
	 * @param  fRecordSet $record_set  The fRecordSet instance
	 * @param  string     $class       The class of the records
	 * @param  array      &$records    The fActiveRecord objects
	 * @param  integer    &$pointer    The current iteration pointer
	 * @return fRecordSet  A set of related records
	 */
	static public function gatherForRandomRecordSet($object, $class, &$records, &$pointer, $parameters)
	{
		$limit          = (isset($parameters[0])) ? $parameters[0] : NULL;
		$classes        = (isset($parameters[1])) ? $parameters[1] : array();
		$sort_direction = (isset($parameters[2])) ? $parameters[2] : 'asc';
		$tag_class      = (isset($parameters[3])) ? $parameters[3] : self::getDefaultTagClass();
		
		if (!in_array($class, array_keys(self::$configured_classes))) {
			throw new fProgrammerException(
				'The method, %1$s, was called on a record set of %2$s objects, however %3$s has not been configured as a tag class. Valid tag classes include: %4$s.',
				'gatherRelated()',
				$class,
				$class,
				join(', ', array_keys(self::$configured_classes))
			);
		}
		
		return self::build($object, $limit, $classes, $sort_direction, $tag_class, NULL, TRUE);
	}
	
	
	/**
	 * Return the default tag class to relate with
	 * 
	 * @return string
	 */
	static private function getDefaultTagClass()
	{
		$keys  = array_keys(self::$configured_classes);
		return $keys[0];
	}
	
	
	/**
	 * If tags are available in post or get, add them to the object during populate()
	 * 
	 * @param  fActiveRecord $object                The object being stored
	 * @param  array         &$values               The current values
	 * @param  array         &$old_values           The old values
	 * @param  array         &$related_records      Any records related to this record
	 * @param  array         &$cache                The cache for the record
	 * @param  array         &$validation_messages  An array of ordered validation messages
	 * @return void
	 */
	static public function populateTags($object, &$values, &$old_values, &$related_records, &$cache, &$validation_messages)
	{
		$class = get_class($object);

		foreach (self::$configured_classes as $configured_tag_class => $configured_classes) {
			if (in_array($class, $configured_classes)) {
				$tag_class = $configured_tag_class;
			}
		}
		
		$tag_table   = fORM::tablize($tag_class);
		$tag_schema  = fORMSchema::retrieve($tag_class);
		$tag_column  = self::$configured_columns[$tag_class];
		$preset_tags = self::$configured_preset_tags[$tag_class];
		
		$build_tags_method = 'build' . fGrammar::pluralize($tag_class);
		$get_tag_method    = 'get' . fGrammar::camelize($tag_column, TRUE);
		$set_tag_method    = 'set' . fGrammar::camelize($tag_column, TRUE);
		
		// clean up tags from the request
		$tags = array_map(
			'trim',
			fRequest::get(
				$tag_table,
				'array',
				array()
			)
		);
		$tags = array_merge(array_filter($tags));
		$tags = array_map(fHTML::decode, $tags);
		$tags = array_map(strtolower, $tags);
		
		// insert new tags
		foreach ($tags as $tag) {
			try {
				$old_tag = new $tag_class($tag);
			} catch (Exception $e) {
				$new_tag = new $tag_class();
				$new_tag->$set_tag_method($tag);
				$new_tag->store();
			}
		}
		
		// garbage collect defunct tags
		$old_tags = $object->$build_tags_method()->filter(array($get_tag_method . '!=' => $tags));
		foreach ($old_tags as $old_tag) {
			if (in_array($old_tag->$get_tag_method(), $preset_tags)) {
				continue;
			}
			
			if (self::gatherRecords($tag_class, $old_tag->$get_tag_method(), NULL)->count() == 1) {
				$old_tag->delete();
			}
		}
		
		fRequest::set($tag_table . '::' . $tag_column, $tags);
		
		fORMRelated::populateRecords($class, $related_records, $tag_class);
	}
	
	
	/**
	 * Adjusts the fActiveRecord::reflect() signatures of columns that have been added by this class
	 * 
	 * @internal
	 * 
	 * @param  string  $class                 The class to reflect
	 * @param  array   &$signatures           The associative array of `{method name} => {signature}`
	 * @param  boolean $include_doc_comments  If doc comments should be included with the signature
	 * @return void
	 */
	static private function reflectGatherRelated($class, &$signatures, $include_doc_comments)
	{
		$signature = '';
		if ($include_doc_comments) {
			$signature .= "/**\n";
			$signature .= " * Gather up records related to tag(s)\n";
			$signature .= " * \n";
			$signature .= " * @param integer $limit           Number of records to return \n";
			$signature .= " * @param array   $classes         An associative array of classes to return records for, where the key is the class and the value is the sorting method (it is recommended not to mix data types for sorting) \n";
			$signature .= " * @param string  $sort_direction  Sort order direction \n";
			$signature .= " * @param mixed   $tag_class       The tag class to pull from, defaults to 'Tag' \n";
			$signature .= " * @return array\n";
			$signature .= " */\n";
		}
		$signature .= 'public function gatherRelated()';
		
		$signatures['gatherRelated'] = $signature;
	}
	
	
	/**
	 * Adjusts the fActiveRecord::reflect() signatures of columns that have been added by this class
	 * 
	 * @internal
	 * 
	 * @param  string  $class                 The class to reflect
	 * @param  array   &$signatures           The associative array of `{method name} => {signature}`
	 * @param  boolean $include_doc_comments  If doc comments should be included with the signature
	 * @return void
	 */
	static private function reflectGatherRelatedRandom($class, &$signatures, $include_doc_comments)
	{
		$signature = '';
		if ($include_doc_comments) {
			$signature .= "/**\n";
			$signature .= " * Gather up records related to tag(s) in a random order\n";
			$signature .= " * \n";
			$signature .= " * @param integer $limit           Number of records to return \n";
			$signature .= " * @param array   $classes         An optional array of classes to return records for \n";
			$signature .= " * @param mixed   $tag_class       The tag class to pull from, defaults to 'Tag' \n";
			$signature .= " * @return array\n";
			$signature .= " */\n";
		}
		$signature .= 'public function gatherRelatedRandom()';
		
		$signatures['gatherRelatedRandom'] = $signature;
	}
	
	
	/**
	 * Replaces the set method with one that converts the tag to lowercase
	 * 
	 * @param  fActiveRecord $object                The object being stored
	 * @param  array         &$values               The current values
	 * @param  array         &$old_values           The old values
	 * @param  array         &$related_records      Any records related to this record
	 * @param  array         &$cache                The cache for the record
	 * @param  string        $method_name           The method that was called
	 * @param  array         &$parameters           The parameteres passed to the method
	 * @return void
	 */
	static public function setTag($object, &$values, &$old_values, &$related_records, &$cache, $method_name, &$parameters)
	{
		$class  = get_class($object);
		$column = self::$configured_columns[$class];
		
		$value = fUTF8::lower($parameters[0]);
		
		if ($value === '') {
			$value = NULL;
		}
		
		fActiveRecord::assign($values, $old_values, $column, $value);
	}
	
	
	/**
	 * Compares the columns of two records by their sorting methods
	 *
	 * @param  string $a  The first record to compare
	 * @param  string $b  The second record to compare
	 * @return integer  `-1` if `$a` is longer than `$b`, `0` if they are equal length, `1` if `$a` is shorter than `$b`, but this is inverted if the sort direction is desc
	 */
	static public function sortRecordsCallback($a, $b)
	{
		$sort_class_keys = array_flip(self::$sort_classes);
		
		$sort_key_a = $sort_class_keys[get_class($a)];
		$sort_key_b = $sort_class_keys[get_class($b)];
		
		$method_a = self::$sort_methods[$sort_key_a];
		$method_b = self::$sort_methods[$sort_key_b];
		
		$order = strnatcasecmp($a->$method_a(), $b->$method_b());
		
		if (self::$sort_direction == 'desc') {
			return -1 * $order;
		}
		
		return $order;
	}
}


/**
 * Copyright (c) 2010 iMarc LLC <info@imarc.net>
 * 
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to deal
 * in the Software without restriction, including without limitation the rights
 * to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 * copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 * 
 * The above copyright notice and this permission notice shall be included in
 * all copies or substantial portions of the Software.
 * 
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 * OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN
 * THE SOFTWARE.
 */