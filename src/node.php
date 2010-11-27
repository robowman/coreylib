<?php
/**
 * coreylib - Standard API for navigating data: XML and JSON
 * Copyright (C)2008-2010 Fat Panda LLC.
 *
 * This program is free software; you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation; either version 2 of the License, or
 * (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License along
 * with this program; if not, write to the Free Software Foundation, Inc.,
 * 51 Franklin Street, Fifth Floor, Boston, MA 02110-1301 USA. 
 */

/**
 * Parser for jQuery-inspired selector syntax.
 */
class clSelector implements ArrayAccess, Iterator {
  
  static $sep = "#(\s+|/)#";
  
  static $regex;
  
  private $selectors = array();
  
  private $i = 0;
  
  static $tokenize = array('#', ';', '&', ',', '.', '+', '*', '~', "'", ':', '"', '!', '^', '$', '[', ']', '(', ')', '=', '>', '|', '/', '@', ' ');
  
  private $tokens;
  
  private function tokenize($string) {
    $tokenized = false;
    foreach(self::$tokenize as $t) {
      while(($at = strpos($string, "\\$t")) !== false) {
        $tokenized = true;
        $token = "TKS".count($this->tokens)."TKE";
        $this->tokens[] = $t;
        $string = substr($string, 0, $at).$token.substr($string, $at+2);
      }
    }
    return $tokenized ? 'TK'.$string : $string;
  }
  
  private function untokenize($string) {
    if (!$string || strpos($string, 'TK') !== 0) {
      return $string;
    } else {
      foreach($this->tokens as $i => $t) {
        $token = "TKS{$i}TKE";
        $string = preg_replace("/{$token}/", $t, $string);
      }
      return substr($string, 2);
    }
  }
  
  function __construct($query) {
    if (!self::$regex) {
      self::$regex = self::generateRegEx();
    }
    
    $tokenized = $this->tokenize($query);
    if (!($selectors = preg_split(self::$sep, $tokenized))) {
      throw new clException("Failed to parse selector query [$query].");
    }
    
    foreach($selectors as $sel) {
      if (!preg_match(self::$regex, $sel, $matches)) {
        throw new clException("Failed to parse [$sel], parse of [$query].");
      }
      
      $sel = (object) array(
        'element' => $this->untokenize(@$matches['element']),
        'is_expression' => $this->untokenize(@$matches['attrib_exp_name']),
        'attrib' => $this->untokenize(@$matches['attrib_exp_name'] ? $matches['attrib_exp_name'] : @$matches['attrib']),
        // in coreylib v1, passing "@attributeName" retrieved a scalar value
        'is_attrib_getter' => preg_match('/^@.*$/', $query),
        'test' => @$matches['test'],
        'value' => $this->untokenize(@$matches['value']),
        'suffixes' => null
      );
      
      if ($suffixes = @$matches['suffix']) {
        $all = array_filter(explode(':', $suffixes));
        $suffixes = array();
        
        foreach($all as $suffix) {
          $open = strpos($suffix, '(');
          $close = strrpos($suffix, ')');
          if ($open !== false && $close !== false) {
            $label = substr($suffix, 0, $open);
            $val = $this->untokenize(substr($suffix, $open+1, $close-$open-1));
          } else {
            $label = $suffix;
            $val = true;
          }
          $suffixes[$label] = $val;
        }
        
        $sel->suffixes = $suffixes;
      }
      
      // alias for eq(), and backwards compat with coreylib v1
      if (!isset($sel->suffixes['eq']) && !is_null($index = @$matches['index'])) {
        $sel->suffixes['eq'] = $index;
      }
      
      $this->selectors[] = $sel;
    }
  }
  
  function __get($name) {
    $sel = $this->selectors[$this->i];
    return $sel->{$name};
  }
  
  function has_suffix($name) {
    $sel = $this->selectors[$this->i];
    return @$sel->suffixes[$name];
  }
  
  function size() {
    return count($this->selectors);
  }
  
  function current() {
    return $this->selectors[$this->i];
  }
  
  function key() {
    return $this->i;
  }
  
  function next() {
    $this->i++;
  }
  
  function rewind() {
    $this->i = 0;
  }
  
  function valid() {
    return isset($this->selectors[$this->i]);
  }
  
  function offsetExists($offset) {
    return isset($this->selectors[$offset]);
  }
  
  function offsetGet($offset) {
    return $this->selectors[$offset];
  }
  
  function offsetSet($offset, $value) {
    throw new clException("clSelector objects are read-only.");
  }
  
  function offsetUnset($offset) {
    throw new clException("clSelector objects are read-only.");
  }
  
  function getSelectors() {
    return $this->selectors;
  }
  
  static function generateRegEx() {
    // "Names and Tokens" http://www.w3.org/TR/REC-xml/
    // TODO: unicode characters accepted: \192-\214\216-\246\248-\767\768-\879\880-\893\895-\8191\8204-\8205\8255-\8256\8304-\8591\11264-\12271\12289-\55295\63744-\64975\65008-\65533\65536-\983039
    $name = "[:A-Za-z0-9\.]+";
    
    // element express with optional index
    $element = "((?P<element>{$name})(\\[(?P<index>[0-9]+)\\])?)";
    
    // attribute expression 
    $attrib = "@(?P<attrib>{$name})";
    
    // tests of equality
    $tests = implode('|', array(
      // Selects elements that have the specified attribute with a value either equal to a given string or starting with that string followed by a hyphen (-).
      "\\|=",
      // Selects elements that have the specified attribute with a value containing the a given substring.
      "\\*=",
      // Selects elements that have the specified attribute with a value containing a given word, delimited by whitespace.
      "~=",
      // Selects elements that have the specified attribute with a value ending exactly with a given string. The comparison is case sensitive.
      "\\$=",
      // Selects elements that have the specified attribute with a value exactly equal to a certain value.
      "=",
      // Select elements that either don't have the specified attribute, or do have the specified attribute but not with a certain value.
      "\\!=",
      // Selects elements that have the specified attribute with a value beginning exactly with a given string.
      "\\^="
    ));
    
    // suffix selectors
    $suffixes = implode('|', array(
      // retun nth element
      ":eq\([0-9]+\)",
      // return the first element
      ":first",
      // return the last element
      ":last",
      // greater than index
      ":gt\([0-9]+\)",
      // less than index
      ":lt\([0-9]+\)",
      // even only
      ":even",
      // odd only
      ":odd"
    ));
    
    $suffix_exp = "(?P<suffix>({$suffixes})+)";
    
    // attribute expression
    $attrib_exp = "\\[@?((?P<attrib_exp_name>{$name})((?P<test>{$tests})\"(?P<value>.*)\")?)\\]";
    
    // the final expression
    return "#^{$element}?(({$attrib})|({$attrib_exp}))*{$suffix_exp}*$#";
  }
  
}

/**
 * Models a discreet unit of data. This unit of data can have attributes (or properties)
 * and children, themselves instances of clNode. 
 */
abstract class clNode {
  
  /**
   * Factory method: return the correct type of clNode.
   * @param $string The content to parse
   * @param string $type The type - supported include "xml" and "json"
   * 
   */
  function getNodeFor($string, $type) {
    if ($type == 'xml') {
      $node = new clXmlNode();
    } else if ($type == 'json') {
      $node = new clJsonNode();
    } else {
      throw new clException("Unsupported Node type: $type");
    }
    
    if (!$node->parse($string)) {
      return false;
    } else {
      return $node;
    }
  }
  
  /**
   * Retrieve the first element or attribute queried by $selector.
   * @param string $selector
   * @return mixed an instance of a clNode subclass, or a scalar value
   * @throws clException When an attribute requested does not exist.
   */ 
  function first($selector) {
    $values = $this->get($selector);
    return is_array($values) ? @$values[0] : $values;
  }
  
  /**
   * Retrieve some data from this Node and/or its children.
   * @param mixed &$selector A query conforming to the coreylib selector syntax, or an instance of clSelector
   * @param int $limit A limit on the number of values to return
   * @param array &$results Results from the previous recursive iteration of ::get
   * @return mixed An array or a single value, given to $selector
   */
  function get($selector, $limit = null, &$results = null) {
    // shorten the variable name, for convenience
    $sel = $selector;
    if (!is_object($sel)) {
      $sel = new clSelector($sel);
      if (!$sel->valid()) {
        // nothing to process
        return array();
      }
    }
    
    if (is_null($results)) {
      $results = array($this);
    } else if (!is_array($results)) {
      $results = array($results);
    } 
    
    if ($sel->element) {
      $agg = array();
      foreach($results as $child) {
        if (is_object($child)) {
          $agg = array_merge($agg, $child->children($sel->element));
        }
      }
      $results = $agg;
    }
    
    
    if (!count($results)) {
      return array();
    }
    
    if ($sel->attrib) {
      if ($sel->is_expression) {
        
        
        
        
        
      } else {
        $agg = array();
        foreach($results as $child) {
          if (is_object($child)) {
            $att = $child->attribute($sel->attrib);
            if (is_array($att)) {
              $agg = array_merge($agg, $att);
            } else {
              $agg[] = $att;
            }
          }
        }
        
        if ($sel->is_attrib_getter) {
          return @$agg[0];
        } else {
          $results = $agg;
        }
      }
    }
    
    if ($sel->suffixes) {
      
    }
    
    if (!count($results)) {
      return array();
    }
      
    $sel->next();
    if ($sel->valid()) {
      $results = $this->get($sel, null, $results);
    }  
    
    if ($limit && is_array($results)) {
      $results = array_slice($results, 0, $limit);
    }
    
    return $results;
  }
  
  protected abstract function attribute($selector = '');
  
  protected abstract function children($selector = '');
  
  abstract function __toString();
  
  abstract function parse($string = '');
  
}

/**
 * JSON implementation of clNode, wraps the results of json_decode.
 */
//class clJsonNode extends clNode {}

/**
 * XML implementation of clNode, wraps instances of SimpleXMLElement.
 */
class clXmlNode extends clNode {
  
  private $el;
  public $parent;
  private $ns;
  public $namespaces;
  
  /**
   * Wrap a SimpleXMLElement object.
   * @param SimpleXMLElement $simple_xml_el
   * @param array $namespaces (optional)
   */
  function __construct(&$simple_xml_el = null, $ns = '', &$namespaces = null) {
    $this->el = $simple_xml_el;
    $this->ns = $ns;
    
    if (!is_null($namespaces)) {
      $this->namespaces = $namespaces;
    }
    
    if (!$this->namespaces && $this->el) {
      $this->namespaces = $this->el->getNamespaces(true);
      $this->namespaces[''] = null;
    }
  }
  
  function parse($string = '') {
    if (($sxe = simplexml_load_string(trim($string))) !== false) {
      $this->el = $sxe;
      $this->namespaces = $this->el->getNamespaces(true);
      $this->namespaces[''] = null;
      return true;
    } else {
      // TODO: in PHP >= 5.1.0, it's possible to silence SimpleXML parsing errors and then iterate over them
      // http://us.php.net/manual/en/function.simplexml-load-string.php
      return false;
    }
  }
  
  /**
   * Expose the SimpleXMLElement API.
   */
  function __call($fx_name, $args) {
    $result = call_user_func_array(array($this->el, $fx_name), $args);
    if ($result instanceof SimpleXMLElement) {
      return new clXmlNode($result);
    } else {
      return $result;
    }
  }
  
  /**
   * Expose the SimpleXMLElement API.
   */
  function __get($name) {
    $result = $this->el->{$name};
    if ($result instanceof SimpleXMLElement) {
      return new clXmlNode($result);
    } else {
      return $result;
    }
  }
  
  private $children;
  
  /**
   * Retrieve children of this node named $selector. The benefit
   * of this over SimpleXMLElement::children() is that this method
   * is namespace agnostic, searching available children until
   * matches are found.
   * @param string $selector A name, e.g., "foo", or a namespace-prefixed name, e.g., "me:foo"
   * @return array of clXmlNodes, when found; otherwise, empty array
   */
  protected function children($selector = '') {    
    if (!$this->children) {
      $this->children = array();
      foreach($this->namespaces as $ns => $uri) {
        $this->children[$ns] = &$this->el->children($ns, true);
      }
    }
    
    @list($ns, $name) = explode(':', $selector);
    
    if (!$name) {
      $name = $ns;
      $ns = null;
    }
    
    $children = array();
    
    // no namespace and no name? get all.
    if (!$ns && !$name) {
      foreach($this->children as $ns => $child_sxe) {
        foreach($child_sxe as $child) {
          $children[] = new clXmlNode($child, $ns, $this->namespaces);
        }
      }
      return $children;
      
    // ns specified?
    } else if ($ns && isset($this->children[$ns])) {
      foreach($this->children[$ns] as $child) {
        if ($child->getName() == $name) {
          $children[] = new clXmlNode($child, $ns);
        }
      }
    
    // looking for the name across all namespaces
    } else {
      foreach($this->children as $ns => $child_sxe) {
        foreach($child_sxe as $child) {
          if ($child->getName() == $name) {
            $children[] = new clXmlNode($child, $ns, $this->namespaces);
          }
        }
      }
    }
    
    return $children;
  }
  
  private $attributes;
  
  /**
   * Retrieve attributes of this node named $selector. The benefit
   * of this over SimpleXMLElement::attributes() is that this method
   * is namespace agnostic, searching available attributes until
   * matches are found.
   * @param string $selector A name, e.g., "foo", or a namespace-prefixed name, e.g., "me:foo"
   * @return mixed a scalar value when $selector is defined; otherwise, an array of all attributes and values
   *  or, when no attribute $selector is found, null
   */
  protected function attribute($selector = '') {
    if (!$this->attributes) {
      $this->attributes = array();
      foreach($this->namespaces as $ns => $uri) {
        $this->attributes[$ns] = $this->el->attributes($ns, true);
      }
    }
    
    @list($ns, $name) = explode(':', $selector);
    
    if (!$name) {
      $name = $ns;
      $ns = null;
    }
    
    // no namespace and no name? get all.
    if (!$ns && !$name) {
      $attributes = array();
      foreach($this->attributes as $ns => $atts) {
        foreach($atts as $this_name => $val) {
          if ($ns) {
            $this_name = "$ns:$this_name";
          }
          $attributes[$this_name] = (string) $val;
        }
      }
      return $attributes;
      
    // ns specified? 
    } else if ($ns && isset($this->attributes[$ns])) {
      foreach($this->attributes[$ns] as $this_name => $val) {
        if ($this_name == $name) {
          return (string) $val;
        }
      }
     
    // looking for the name across all namespaces
    } else {
      foreach($this->attributes as $ns => $atts) {
        foreach($atts as $this_name => $val) {
          if ($this_name == $name) {
            return (string) $val;
          }
        }
      }
    }
    
    return null;
  }
  
  /**
   * Use XPATH to select elements. But... why? Ugh.
   * @param 
   * @return clXmlNode
   * @deprecated Use clXmlNode::get($selector, true) instead.
   */
  function xpath($selector) {
    return new clXmlNode($this->el->xpath($selector));
  }
  
  function __toString() {
    return (string) $this->el;
  }
  
  function info() {
    
  }
  
}