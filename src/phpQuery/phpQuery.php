<?php

namespace phpQuery;

/*
 * phpQuery is a server-side, chainable, CSS3 selector driven
 * Document Object Model (DOM) API based on jQuery JavaScript Library.
 */

use DOMDocument;
use DOMNode;
use DOMNodeList;
use Exception;

/**
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 */
abstract class phpQuery
{
    public static $documents = [];
    public static $defaultDocumentID = null;
    /**
     * Applies only to HTML.
     */
    public static $defaultDoctype = '<!DOCTYPE HTML PUBLIC "-//W3C//DTD HTML 4.01 Transitional//EN"
"http://www.w3.org/TR/html4/loose.dtd">';
    public static $defaultCharset = 'UTF-8';

    /**
     * Multi-purpose function.
     * Use pq() as shortcut.
     *
     * In below examples, $pq is any result of pq(); function.
     *
     * 1. Import markup into existing document (without any attaching):
     * - Import into selected document:
     *   pq('<div/>')                // DOESNT accept text nodes at beginning of input string !
     * - Import into document with ID from $pq->getDocumentID():
     *   pq('<div/>', $pq->getDocumentID())
     * - Import into same document as DOMNode belongs to:
     *   pq('<div/>', DOMNode)
     * - Import into document from phpQuery object:
     *   pq('<div/>', $pq)
     *
     * 2. Run query:
     * - Run query on last selected document:
     *   pq('div.myClass')
     * - Run query on document with ID from $pq->getDocumentID():
     *   pq('div.myClass', $pq->getDocumentID())
     * - Run query on same document as DOMNode belongs to and use node(s)as root for query:
     *   pq('div.myClass', DOMNode)
     * - Run query on document from phpQuery object
     *   and use object's stack as root node(s) for query:
     *   pq('div.myClass', $pq)
     *
     * @param string|DOMNode|DOMNodeList|array $arg1 HTML markup, CSS Selector, DOMNode or array of DOMNodes
     * @param string|phpQueryObject|DOMNode $context DOM ID from $pq->getDocumentID(), phpQuery object (determines also query root) or DOMNode (determines also query root)
     * phpQuery object or false in case of error.
     * @throws Exception
     */
    public static function pq($arg1, $context = null)
    {
        if ($arg1 instanceof DOMNode && !isset($context)) {
            foreach (phpQuery::$documents as $documentWrapper) {
                $compare = $arg1 instanceof DOMDocument
                    ? $arg1 : $arg1->ownerDocument;
                if ($documentWrapper->document->isSameNode($compare)) {
                    $context = $documentWrapper->id;
                }
            }
        }
        if (!$context) {
            $domId = self::$defaultDocumentID;
            if (!$domId) {
                throw new Exception("Can't use last created DOM, because there isn't any. Use phpQuery::newDocument() first.");
            }
        } elseif ($context instanceof phpQueryObject) {
            $domId = $context->getDocumentID();
        } elseif ($context instanceof DOMDocument) {
            $domId = self::getDocumentID($context);
            if (!$domId) {
                $domId = self::newDocument($context)->getDocumentID();
            }
        } elseif ($context instanceof DOMNode) {
            $domId = self::getDocumentID($context);
            if (!$domId) {
                throw new Exception('Orphaned DOMNode');
            }
        } else {
            $domId = $context;
        }
        if ($arg1 instanceof phpQueryObject) {
            /**
             * Return $arg1 or import $arg1 stack if document differs:
             * pq(pq('<div/>')).
             */
            if ($arg1->getDocumentID() == $domId) {
                return $arg1;
            }
            $class = get_class($arg1);
            // support inheritance by passing old object to overloaded constructor
            $phpQuery = $class != 'phpQuery'
                ? new $class($arg1, $domId)
                : new phpQueryObject($domId);
            $phpQuery->elements = [];
            foreach ($arg1->elements as $node) {
                $phpQuery->elements[] = $phpQuery->document->importNode($node, true);
            }

            return $phpQuery;
        } elseif ($arg1 instanceof DOMNode || (is_array($arg1) && isset($arg1[0]) && $arg1[0] instanceof DOMNode)) {
            /*
             * Wrap DOM nodes with phpQuery object, import into document when needed:
             * pq(array($DOMNode1, $DOMNode2))
             */
            $phpQuery = new phpQueryObject($domId);
            if (!($arg1 instanceof DOMNodeList) && !is_array($arg1)) {
                $arg1 = [$arg1];
            }
            $phpQuery->elements = [];
            foreach ($arg1 as $node) {
                $sameDocument = $node->ownerDocument instanceof DOMDocument
                    && !$node->ownerDocument->isSameNode($phpQuery->document);
                $phpQuery->elements[] = $sameDocument
                    ? $phpQuery->document->importNode($node, true)
                    : $node;
            }

            return $phpQuery;
        } else {
            /**
             * Run CSS query:
             * pq('div.myClass').
             */
            $phpQuery = new phpQueryObject($domId);
            if ($context && $context instanceof phpQueryObject) {
                $phpQuery->elements = $context->elements;
            } elseif ($context && $context instanceof DOMNodeList) {
                $phpQuery->elements = [];
                foreach ($context as $node) {
                    $phpQuery->elements[] = $node;
                }
            } elseif ($context && $context instanceof DOMNode) {
                $phpQuery->elements = [$context];
            }

            return $phpQuery->find($arg1);
        }
    }

    /**
     * Sets default document to $id. Document has to be loaded prior
     * to using this method.
     * $id can be retrived via getDocumentID() or getDocumentIDRef().
     */
    public static function selectDocument($id)
    {
        $id = self::getDocumentID($id);

        self::$defaultDocumentID = self::getDocumentID($id);
    }

    /**
     * Returns document with id $id or last used as phpQueryObject.
     * $id can be retrived via getDocumentID() or getDocumentIDRef().
     * Chainable.
     *
     * @throws Exception
     * @see phpQuery::selectDocument()
     */
    public static function getDocument($id = null)
    {
        if ($id) {
            phpQuery::selectDocument($id);
        } else {
            $id = phpQuery::$defaultDocumentID;
        }

        return new phpQueryObject($id);
    }

    /**
     * Creates new document from markup.
     * Chainable.
     */
    public static function newDocument($markup = null, $contentType = null)
    {
        if (!$markup) {
            $markup = '';
        }
        $documentID = phpQuery::createDocumentWrapper($markup, $contentType);

        return new phpQueryObject($documentID);
    }

    /**
     * Creates new document from markup.
     * Chainable.
     */
    public static function newDocumentHTML($markup = null, $charset = null)
    {
        $contentType = $charset
            ? ";charset=$charset"
            : '';

        return self::newDocument($markup, "text/html{$contentType}");
    }

    /**
     * Creates new document from markup.
     * Chainable.
     */
    public static function newDocumentXML($markup = null, $charset = null)
    {
        $contentType = $charset
            ? ";charset=$charset"
            : '';

        return self::newDocument($markup, "text/xml{$contentType}");
    }

    /**
     * Creates new document from markup.
     * Chainable.
     */
    public static function newDocumentXHTML($markup = null, $charset = null)
    {
        $contentType = $charset
            ? ";charset=$charset"
            : '';

        return self::newDocument($markup, "application/xhtml+xml{$contentType}");
    }

    protected static function createDocumentWrapper($html, $contentType = null, $documentID = null)
    {
        if (function_exists('domxml_open_mem')) {
            throw new Exception("Old PHP4 DOM XML extension detected. phpQuery won't work until this extension is enabled.");
        }
        if ($html instanceof DOMDocument) {
            if (!self::getDocumentID($html)) {
                $wrapper = new DOMDocumentWrapper($html, $contentType, $documentID);
            }
        } else {
            $wrapper = new DOMDocumentWrapper($html, $contentType, $documentID);
        }
        phpQuery::$documents[$wrapper->id] = $wrapper;
        phpQuery::selectDocument($wrapper->id);

        return $wrapper->id;
    }

    /**
     * Unloades all or specified document from memory.
     *
     * @param mixed $documentID @see phpQuery::getDocumentID() for supported types.
     */
    public static function unloadDocuments($id = null)
    {
        if (isset($id)) {
            if ($id = self::getDocumentID($id)) {
                unset(phpQuery::$documents[$id]);
            }
        } else {
            foreach (phpQuery::$documents as $k => $v) {
                unset(phpQuery::$documents[$k]);
            }
        }
    }

    public static function DOMNodeListToArray($DOMNodeList)
    {
        $array = [];
        if (!$DOMNodeList) {
            return $array;
        }
        foreach ($DOMNodeList as $node) {
            $array[] = $node;
        }

        return $array;
    }

    /**
     * @param array|phpQuery $data
     */
    public static function param($data)
    {
        return http_build_query($data, null, '&');
    }

    /**
     * Returns source's document ID.
     */
    public static function getDocumentID(DOMDocument|DOMNode|phpQueryObject|string $source): string
    {
        if ($source instanceof DOMDocument) {
            foreach (phpQuery::$documents as $id => $document) {
                if ($source->isSameNode($document->document)) {
                    return $id;
                }
            }
        } elseif ($source instanceof DOMNode) {
            foreach (phpQuery::$documents as $id => $document) {
                if ($source->ownerDocument->isSameNode($document->document)) {
                    return $id;
                }
            }
        } elseif ($source instanceof phpQueryObject) {
            return $source->getDocumentID();
        } elseif (is_string($source) && isset(phpQuery::$documents[$source])) {
            return $source;
        }

        return '';
    }

    public static function inArray($value, $array)
    {
        return in_array($value, $array);
    }

    /**
     * @link http://docs.jquery.com/Utilities/jQuery.map
     */
    public static function map($array, $callback, $param1 = null, $param2 = null, $param3 = null)
    {
        $result = [];
        $paramStructure = null;
        if (func_num_args() > 2) {
            $paramStructure = func_get_args();
            $paramStructure = array_slice($paramStructure, 2);
        }
        foreach ($array as $v) {
            $vv = $callback($v, ...$paramStructure);
            if (is_array($vv)) {
                foreach ($vv as $vvv) {
                    $result[] = $vvv;
                }
            } elseif ($vv !== null) {
                $result[] = $vv;
            }
        }

        return $result;
    }

    /**
     * Merge 2 phpQuery objects.
     * @param array $one
     * @param array $two
     * @protected
     */
    public static function merge($one, $two)
    {
        $elements = $one->elements;
        foreach ($two->elements as $node) {
            $exists = false;
            foreach ($elements as $node2) {
                if ($node2->isSameNode($node)) {
                    $exists = true;
                }
            }
            if (!$exists) {
                $elements[] = $node;
            }
        }

        return $elements;
    }

    public static function trim($str)
    {
        return trim($str);
    }

    /**
     * @param $type
     * @param $code
     * @return string
     */
    public static function code($type, $code)
    {
        return "<$type><!-- " . trim($code) . " --></$type>";
    }

    protected static function dataSetupNode($node, $documentID)
    {
        foreach (phpQuery::$documents[$documentID]->dataNodes as $dataNode) {
            if ($node->isSameNode($dataNode)) {
                return $dataNode;
            }
        }

        phpQuery::$documents[$documentID]->dataNodes[] = $node;

        return $node;
    }

    protected static function dataRemoveNode($node, $documentID)
    {
        // search are return if alredy exists
        foreach (phpQuery::$documents[$documentID]->dataNodes as $k => $dataNode) {
            if ($node->isSameNode($dataNode)) {
                unset(self::$documents[$documentID]->dataNodes[$k]);
                unset(self::$documents[$documentID]->data[$dataNode->dataID]);
            }
        }
    }

    public static function data($node, $name, $data, $documentID = null)
    {
        if (!$documentID) {
            $documentID = self::getDocumentID($node);
        }
        $document = phpQuery::$documents[$documentID];
        $node = self::dataSetupNode($node, $documentID);
        if (!isset($node->dataID)) {
            $node->dataID = ++phpQuery::$documents[$documentID]->uuid;
        }
        $id = $node->dataID;
        if (!isset($document->data[$id])) {
            $document->data[$id] = [];
        }
        if (!is_null($data)) {
            $document->data[$id][$name] = $data;
        }
        if ($name) {
            if (isset($document->data[$id][$name])) {
                return $document->data[$id][$name];
            }
        } else {
            return $id;
        }
    }

    public static function removeData($node, $name, $documentID)
    {
        if (!$documentID) {
            $documentID = self::getDocumentID($node);
        }
        $document = phpQuery::$documents[$documentID];
        $node = self::dataSetupNode($node, $documentID);
        $id = $node->dataID;
        if ($name) {
            if (isset($document->data[$id][$name])) {
                unset($document->data[$id][$name]);
            }
            $name = null;
            foreach ($document->data[$id] as $name) {
                break;
            }
            if (!$name) {
                self::removeData($node, $name, $documentID);
            }
        } else {
            self::dataRemoveNode($node, $documentID);
        }
    }
}
