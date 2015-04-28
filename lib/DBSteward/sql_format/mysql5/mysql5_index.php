<?php
/**
 * Manipulate table index definition nodes
 *
 * @package DBSteward
 * @subpackage mysql5
 * @license http://www.opensource.org/licenses/bsd-license.php Simplified BSD License
 * @author Austin Hyde <austin109@gmail.com>
 */

require_once __DIR__ . '/mysql5.php';
require_once __DIR__ . '/../sql99/sql99_index.php';

class mysql5_index extends sql99_index {

  public static function get_table_index($node_schema, $node_table, $name) {
    $indexes = self::get_table_indexes($node_schema, $node_table);
    $return_index = NULL;
    foreach ($indexes AS $index) {
      if (strcasecmp($index['name'], $name) == 0) {
        if ($return_index === NULL) {
          $return_index = $index;
        }
        else {
          throw new exception("more than one table " . $node_schema['name'] . '.' . $node_table['name'] . " index called " . $name . " found");
        }
      }
    }
    return $return_index;
  }

  public static function get_table_indexes($node_schema, $node_table) {
    $nodes = $node_table->xpath("index");

    // add column unique indexes and column fkeys to the list
    foreach ($node_table->column AS $column) {
      if (isset($column['type']) && strcasecmp($column['type'], 'unique') == 0) {
        $unique_index = new SimpleXMLElement('<index/>');
        // For MySQL unique indexes, this is as simple as the column name: http://dev.mysql.com/doc/refman/5.5/en/create-table.html (third of the way down the page)
        // duplicate index names get a suffix: _2, _3, _4
        $unique_index['name'] = static::get_index_name($column['name'], $nodes);
        $unique_index['type'] = 'unique';
        $unique_index['using'] = 'btree';
        $unique_index->addChild('indexDimension', $column['name'])
          ->addAttribute('name', $column['name'] . '_unq');
        $nodes[] = $unique_index;
      }
      elseif (isset($column['foreignColumn'])) {
        // mysql automatically creates indexes for foreign keys if there isn't one already,
        // so we need to create one as well to avoid invalid diffs

        // first, see if there's already an index of JUST this column
        $found = (string)$node_table['primaryKey'] == (string)$column['name'];
        if (!$found) {
          foreach ($nodes as $node) {
            if ((string)$node->indexDimension[0] == (string)$column['name']) {
              $found = true;
              break;
            }
          }
        }
 
        if (!$found) {
          // no? then create one
          $fkey_index = new SimpleXMLElement('<index/>');
          $fkey_index['name'] = (string)$column['foreignIndexName'] 
                              ?: (string)$column['foreignKeyName']
                              ?: static::get_index_name((string)$column['name'], $nodes);
          $fkey_index['using'] = 'btree';
          $fkey_index->addChild('indexDimension', (string)$column['name'])
                     ->addAttribute('name', $column['name'].'_1');
          $nodes[] = $fkey_index;
        }
      }
    }

    $names = array();
    foreach ($nodes as $node) {
      if (in_array((string)$node['name'], $names)) {
        throw new Exception("Duplicate index name {$node['name']} on table {$node_schema['name']}.{$node_table['name']}");
      }
      else {
        $names[] = (string)$node['name'];
      }
    }
    return $nodes;
  }

  protected static function get_index_name($column, $index_nodes) {
    $need_suffix = false;
    $suffix = 0;

    foreach ($index_nodes as $index_node) {
      if (preg_match('/^'.preg_quote($column).'(?:_(\d+))?$/i', $index_node['name'], $matches) > 0) {
        if (isset($matches[1])) {
          $suffix = max($suffix, $matches[1]+1);
        }
        else {
          $need_suffix = true;
          $suffix = max($suffix, 2);
        }
      }
    }

    if ($need_suffix) {
      return $column . '_' . $suffix;
    }
    else {
      return $column;
    }
  }

  /**
   * Creates and returns SQL for creation of the index.
   *
   * @return created SQL
   */
  public static function get_creation_sql($node_schema, $node_table, $node_index) {
    $index_note = "-- note that MySQL does not support indexed expressions or named dimensions\n";
    $sql = "CREATE ";

    if ( !empty($node_index['type']) ) {
      $sql .=  self::get_index_type_sql($node_index['type']).' ';
    }

    $sql .= "INDEX "
      . mysql5::get_quoted_object_name($node_index['name'])
      . " ON "
      . mysql5::get_fully_qualified_table_name($node_schema['name'], $node_table['name']);
    
    $dimensions = static::get_dimension_list($node_schema, $node_table, $node_index);
    $sql .= ' (' . implode(', ', $dimensions) . ')';

    if ( !empty($node_index['using']) ) {
      $sql .= ' USING ' . static::get_using_option_sql($node_index['using']);
    }
    
    //@TODO: mysql5 partial indexes with indexWhere - see pgsql8_index

    return $index_note.$sql.';';
  }

  public static function get_drop_sql($node_schema, $node_table, $node_index) {
    return "DROP INDEX " . mysql5::get_quoted_object_name($node_index['name']) . " ON " . mysql5::get_fully_qualified_table_name($node_schema['name'], $node_table['name']) . ";";
  }

  /**
   * Get creation SQL suitable for use in an ALTER TABLE statement
   * @param  SimpleXMLElement $node_schema
   * @param  SimpleXMLElement $node_table
   * @param  SimpleXMLElement $node_index
   * @return string
   */
  public static function get_alter_add_sql($node_schema, $node_table, $node_index) {
    $dimensions = static::get_dimension_list($node_schema, $node_table, $node_index);

    $type = '';
    if ( !empty($node_index['type']) ) {
      $type = static::get_index_type_sql($node_index['type']).' ';
    }

    $using = '';
    if ( !empty($node_index['using']) ) {
      $using = ' USING ' . static::get_using_option_sql($node_index['using']);
    }
    return 'ADD ' . $type . 'INDEX ' . mysql5::get_quoted_object_name($node_index['name']) . ' (' . implode(', ', $dimensions) . ')' . $using;
  }

  /**
   * Get drop SQL suitable for use in an ALTER TABLE statement
   * @param  SimpleXMLElement $node_schema
   * @param  SimpleXMLElement $node_table
   * @param  SimpleXMLElement $node_index
   * @return string
   */
  public static function get_alter_drop_sql($node_schema, $node_table, $node_index) {
    return 'DROP INDEX ' . mysql5::get_quoted_object_name($node_index['name']);
  }

  protected static function get_dimension_list($node_schema, $node_table, $node_index) {
    $dimensions = array();

    foreach ( $node_index->indexDimension as $dimension ) {
      // mysql only supports indexed columns, not indexed expressions like in pgsql or mssql
      if ( ! mysql5_table::contains_column($node_table, $dimension) ) {
        throw new Exception("Table " . mysql5::get_fully_qualified_table_name($node_schema['name'], $node_table['name']) . " does not contain column '$dimension'");
      }

      $quoted_name = mysql5::get_quoted_column_name($dimension);
      $paren_prefix =  ( $dimension['prefixLength'] > 0 ? '('.$dimension['prefixLength'].')' : NULL );

      $dimensions[] = $quoted_name.$paren_prefix;
    }
    return $dimensions;
  }

  public static function get_using_option_sql($using) {
    $using = strtoupper((string)$using);

    switch ( $using ) {
      case 'HASH':
      case 'BTREE':
        return $using;
        break;

      default:
        dbsteward::console_line(1, "MySQL does not support the $using index type, defaulting to BTREE");
        return 'BTREE';
        break;
    }
  }

  public static function get_index_type_sql($type) {
    $type = strtoupper((string)$type);

    switch ( $type ) {
      case 'UNIQUE':
      case 'SPATIAL':
      case 'FULLTEXT':
        return $type;
        break;

      default:
        dbsteward::console_line(1, "MySQL does not support the $type index type, defaulting to ''");
        return '';
        break;
    }
  }
}
?>
