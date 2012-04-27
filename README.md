# Flourish ORM plugin for tagging

## Requires

```sql
CREATE TABLE tags (
  tag VARCHAR(255) PRIMARY KEY
);
```

```sql
CREATE TABLE tags_related_table (
  related_table_id INTEGER NOT NULL references related_table(related_table_id) ON DELETE CASCADE,
  tag VARCHAR(255) NOT NULL references tags(tag) ON DELETE RESTRICT ON UPDATE CASCADE,
  PRIMARY KEY (tag, related_table_id)
);
```

## Usage

Add linking table(s) to a tags table (see above tag_related_table for an example).

To initialize, call ```fpORMTagging::configure()``` in your init file on whichever tagging class you wish

## Example: Tags to blog posts

* Required table ```tags```
* Table named ```blog_posts``` maps to class ```BlogPost```.
* Table named ```blog_post_tags``` maps to class ```BlogPostTag```

```sql
CREATE TABLE tags (
  tag VARCHAR(255) PRIMARY KEY
);

CREATE TABLE blog_posts (
  post_id INTEGER AUTOINCREMENT PRIMARY KEY,
  title VARCHAR(255) NOT NULL,
  content TEXT NOT NULL,
  date_created TIMESTAMP NOT NULL,
  date_updated TIMESTAMP NOT NULL,
  timezone VARCHAR(128) NOT NULL
);

CREATE TABLE blog_post_tags (
  post_id INTEGER NOT NULL REFERENCES blog_posts(post_id) ON DELETE CASCADE ON UPDATE CASCADE,
  tag VARCHAR(255) NOT NULL REFERENCES tags(tag) ON DELETE RESTRICT ON UPDATE CASCADE,
  PRIMARY KEY (tag, post_id)
);
```

```php
<?php
class Tag extends fActiveRecord {}

class BlogPost extends fActiveRecord {
  protected function configure() {    
    fORMDate::configureDateCreatedColumn($this, 'date_created');
    fORMDate::configureDateUpdatedColumn($this, 'date_updated');
    fORMDate::configureTimezoneColumn($this, 'timezone');
  }
}

class BlogPostTag extends fActiveRecord {
  protected function configure() {
    fORMTagging::configure($this, 'tag', array());
  }
}
```

### Handling a form

```php
<?php // Tags is fRecordSet ?>
<form method="post" action="/blog-post">
  <input type="text" name="title" value="<?php print fRequest::encode('title', 'string', ''); ?>">
  <textarea name="content"></textarea>
  <?php foreach ($tags as $tag): ?>
    <?php // format for name is underscore_related_table_name::foreign_column_name[] ?>
    <input type="checkbox" name="blog_post_tags::tag[]" value="<?php print $tag->encodeTag(); ?>">
  <?php endforeach; ?>
  <input type="submit" name="action" value="<?php print fHTML::encode('Create New Blog Post'); ?>">
</form>
```

### Code to handle this request

```php
<?php
$blog_post = new BlogPost;
$blog_post->populate();
$blog_post->linkTags();
$blog_post->store();
