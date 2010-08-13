<?php
/**
 * Flourish ORM plugin for tagging
 *
 * Requires:
 *
 * CREATE TABLE tags (
 *    tag VARCHAR(255) UNIQUE PRIMARY KEY
 * );
 *
 * CREATE TABLE tag_related_table (
 *    related_table_id INTEGER NOT NULL references related_table(related_table_id) ON DELETE CASCADE,
 *    tag VARCHAR(255) NOT NULL references tags(tag) ON DELETE CASCADE ON UPDATE CASCADE,
 *    PRIMARY KEY (tag, related_table_id)
 * );
 *
 * Usage:
 *
 * Add linking table(s) to a tags table (see above tag_related_table for an example).
 *
 * To initialize, call fpORMTagging::configure('ClassName') in your init file on whichever tagging class you wish
 *
 * @copyright  2010, iMarc <info@imarc.net>
 * @author     Craig Ruks [cr] <craigruk@imarc.net>
 * @author     Will Bond [wb] <will@imarc.net>
 *
 * @package    Flourish Plugins
 *
 * @version 1.0
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
	 * Configures tagging for every class related to the tag class passed in
	 *
	 * @param string $class        The name of the tag class
	 * @param string $column       The tag column for the tag class
	 * @param array  $preset_tags  Any preset tags that cannot be deleted
	 * @return void
	 */
	static public function configure($class, $column, $preset_tags=array())
	{
		$class         = fORM::getClass($class);
		$table         = fORM::tablize($class);
		$schema        = fORMSchema::retrieve($class);
		$data_type     = $schema->getColumnInfo($table, $column, 'type');
		$relationships = $schema->getRelationships('tags', 'many-to-many');
		
		// configure static settings for this instance
		self::$configured_classes[$class]     = array();
		self::$configured_columns[$class]     = $column;
		self::$configured_preset_tags[$class] = $preset_tags;
		
		// add hook to lowercase tag when stored
		fORM::registerHookCallback($class, 'post-validate::store()', __CLASS__ . '::lowerCase');
		
		// add hooks to all classes that use tags
		foreach ($relationships as $relationship) {
			self::$configured_classes[$class][] = fORM::classize($relationship['related_table']);
			fORM::registerHookCallback(fORM::classize($relationship['related_table']), 'pre::validate()',  __CLASS__ . '::populateTags');
		}
		
		// insert preset tags
		foreach ($preset_tags as $tag) {
			try {
				$old_tag = new Tag($tag);
			} catch (Exception $e) {
				$new_tag = new Tag();
				$new_tag->setTag($tag);
				$new_tag->store();
			}
		}
	}
	
	
	/**
	 * Deletes unused tags, garbage collecting method
	 *
	 * @param string $class  The name of the tag class
	 * @return void
	 */
	static public function deleteDefunctTags($class)
	{
		$class          = fORM::getClass($class);
		$table          = fORM::tablize($class);
		$column         = self::$configured_columns[$class];
		$preset_tags    = self::$configured_preset_tags[$class];
		$get_tag_method = 'get' . fGrammar::camelize($column, TRUE);
		
		$tags = fRecordSet::buildFromSql($class, 'SELECT * FROM ' . $table);
		
		foreach ($tags as $tag) {
			if (in_array($tag->$get_tag_method(), $preset_tags)) {
				continue;
			}
			if (self::gatherRecords($class, $tag->$get_tag_method(), NULL)->count() == 0) {
				$tag->delete();
			}
		}
	}
	
	
	/**
	 * Gather up records related to tag(s)
	 *
	 * @param mixed   $tag_class  The tag class to pull from
	 * @param mixed   $tags       The tags to filter by (can be string, array, or fRecordSet)
	 * @param integer $limit      Number of records to return
	 * @param array   $classes    An associative array of classes to return records for, where the key is the class and the value is the sorting method (it is recommended not to mix data types for sorting)
	 * @return array
	 */
	static public function gatherRecords($tag_class, $tags, $limit=10, $classes=array(), $sort_direction='asc')
	{
		$tag_class  = fORM::getClass($tag_class);
		$tag_table  = fORM::tablize($tag_class);
		$tag_schema = fORMSchema::retrieve($tag_class);
		$tag_column = self::$configured_columns[$tag_class];
		
		if ($tags instanceOf fRecordSet) {
			$tags = $tags->getRecords();
		}
		
		if (sizeof($tags) == 1) {
			$tags = preg_split('#\s*,\s*#', $tags);
		}
		
		if (!$classes) {
			$relationships = $tag_schema->getRelationships($tag_table, 'many-to-many');
			
			foreach ($relationships as $relationship) {
				$route = fORMSchema::getRouteNameFromRelationship('many-to-many', $relationship);
				$classes[fORM::classize($relationship['related_table'])] = '__toString';
			}
		}
		
		$set = fRecordSet::buildFromArray(key($classes), array());
		
		foreach ($classes as $class => $method) {
			$where_conditions = array($tag_table . '.' . $tag_column . '=' => $tags);
			
			$record_set = fRecordSet::build($class, $where_conditions, NULL, $limit);
			
			$set = $set->merge($record_set);
		}
		
		self::$sort_classes   = $classes;
		self::$sort_direction = $sort_direction;
		
		return $set->sortByCallback('fpORMTagging::sortRecordsCallback')->slice(0, $limit);
	}
	
	
	/**
	 * Hook callback method to lowercase the tag
	 * 
	 * @param  fActiveRecord $object                The object being stored
	 * @param  array         &$values               The current values
	 * @param  array         &$old_values           The old values
	 * @param  array         &$related_records      Any records related to this record
	 * @param  array         &$cache                The cache for the record
	 * @param  array         &$validation_messages  An array of ordered validation messages
	 * @return void
	 */
	public static function lowerCase($object, &$values, &$old_values, &$related_records, &$cache, &$validation_messages)
	{
		$class          = get_class($object);
		$column         = self::$configured_columns[$class];
		$get_tag_method = 'get' . fGrammar::camelize($column, TRUE);
		$set_tag_method = 'set' . fGrammar::camelize($column, TRUE);
		
		$object->$set_tag_method(strtolower($object->$get_tag_method()));
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
	 * Compares the columns of two records by their sorting methods
	 *
	 * @param  string $a  The first record to compare
	 * @param  string $b  The second record to compare
	 * @return integer  `-1` if `$a` is longer than `$b`, `0` if they are equal length, `1` if `$a` is shorter than `$b`, but this is inverted if the sort direction is desc
	 */
	static public function sortRecordsCallback($a, $b)
	{
		$method_a = self::$sort_classes[get_class($a)];
		$method_b = self::$sort_classes[get_class($b)];
		
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