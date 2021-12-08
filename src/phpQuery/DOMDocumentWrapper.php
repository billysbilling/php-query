<?php

namespace phpQuery;

use DOMDocument;
use DOMNode;
use DOMNodeList;
use DOMXPath;
use Exception;

/**
 * DOMDocumentWrapper class simplifies work with DOMDocument.
 *
 * Know bug:
 * - in XHTML fragments, <br /> changes to <br clear="none" />
 *
 * @author Tobiasz Cudnik <tobiasz.cudnik/gmail.com>
 */
class DOMDocumentWrapper
{
    /**
     * @var DOMDocument
     */
    public $document;
    public $id;

    public $contentType = '';
    public $xpath;
    public $data = [];
    public $events = [];

    /**
     * Document root, by default equals to document itself.
     * Used by documentFragments.
     *
     * @var DOMNode
     */
    public $root;
    public $isDocumentFragment;
    public $isXML = false;
    public $isXHTML = false;
    public $isHTML = false;
    public $charset;

    public function __construct($markup = null, $contentType = null, $newDocumentID = null)
    {
        if (isset($markup)) {
            $this->load($markup, $contentType, $newDocumentID);
        }
        $this->id = $newDocumentID || md5(microtime());
    }

    public function load($markup, $contentType = null, $newDocumentID = null)
    {
        $this->contentType = strtolower($contentType);
        if ($markup instanceof DOMDocument) {
            $this->document = $markup;
            $this->root = $this->document;
            $this->charset = $this->document->encoding;
        } else {
            $loaded = $this->loadMarkup($markup);
        }
        if ($loaded) {
            $this->document->preserveWhiteSpace = true;
            $this->xpath = new DOMXPath($this->document);
            $this->afterMarkupLoad();

            return true;
        }

        return false;
    }

    protected function afterMarkupLoad()
    {
        if ($this->isXHTML) {
            $this->xpath->registerNamespace('html', 'http://www.w3.org/1999/xhtml');
        }
    }

    protected function loadMarkup($markup)
    {
        $loaded = false;
        if ($this->contentType) {

            // content determined by contentType
            list($contentType, $charset) = $this->contentTypeToArray($this->contentType);
            switch ($contentType) {
                case 'text/html':
                    $loaded = $this->loadMarkupHTML($markup, $charset);
                break;
                case 'text/xml':
                case 'application/xhtml+xml':
                    $loaded = $this->loadMarkupXML($markup, $charset);
                break;
                default:
                    // for feeds or anything that sometimes doesn't use text/xml
                    if (strpos('xml', $this->contentType) !== false) {
                        $loaded = $this->loadMarkupXML($markup, $charset);
                    }
            }
        } else {
            // content type autodetection
            if ($this->isXML($markup)) {
                $loaded = $this->loadMarkupXML($markup);
                if (!$loaded && $this->isXHTML) {
                    $loaded = $this->loadMarkupHTML($markup);
                }
            } else {
                $loaded = $this->loadMarkupHTML($markup);
            }
        }

        return $loaded;
    }

    protected function loadMarkupReset()
    {
        $this->isXML = $this->isXHTML = $this->isHTML = false;
    }

    protected function documentCreate($charset, $version = '1.0')
    {
        if (!$version) {
            $version = '1.0';
        }
        $this->document = new DOMDocument($version, $charset);
        $this->charset = $this->document->encoding;
        $this->document->formatOutput = true;
        $this->document->preserveWhiteSpace = true;
    }

    protected function loadMarkupHTML($markup, $requestedCharset = null)
    {
        $this->loadMarkupReset();
        $this->isHTML = true;
        if (!isset($this->isDocumentFragment)) {
            $this->isDocumentFragment = self::isDocumentFragmentHTML($markup);
        }
        $charset = null;
        $documentCharset = $this->charsetFromHTML($markup);
        $addDocumentCharset = false;
        if ($documentCharset) {
            $charset = $documentCharset;
            $markup = $this->charsetFixHTML($markup);
        } elseif ($requestedCharset) {
            $charset = $requestedCharset;
        }
        if (!$charset) {
            $charset = phpQuery::$defaultCharset;
        }
        // HTTP 1.1 says that the default charset is ISO-8859-1
        // @see http://www.w3.org/International/O-HTTP-charset
        if (!$documentCharset) {
            $documentCharset = 'ISO-8859-1';
            $addDocumentCharset = true;
        }
        // Should be careful here, still need 'magic encoding detection' since lots of pages have other 'default encoding'
        // Worse, some pages can have mixed encodings... we'll try not to worry about that
        $requestedCharset = strtoupper($requestedCharset);
        $documentCharset = strtoupper($documentCharset);
        if ($requestedCharset && $documentCharset && $requestedCharset !== $documentCharset) {
            // Document Encoding Conversion
            // http://code.google.com/p/phpquery/issues/detail?id=86
            if (function_exists('mb_detect_encoding')) {
                $possibleCharsets = [$documentCharset, $requestedCharset, 'AUTO'];
                $docEncoding = mb_detect_encoding($markup, implode(', ', $possibleCharsets));
                if (!$docEncoding) {
                    $docEncoding = $documentCharset;
                } // ok trust the document
                if ($docEncoding !== $requestedCharset) {
                    $markup = mb_convert_encoding($markup, $requestedCharset, $docEncoding);
                    $markup = $this->charsetAppendToHTML($markup, $requestedCharset);
                    $charset = $requestedCharset;
                }
            }
        }
        $return = false;
        if ($this->isDocumentFragment) {
            $return = $this->documentFragmentLoadMarkup($this, $charset, $markup);
        } else {
            if ($addDocumentCharset) {
                $markup = $this->charsetAppendToHTML($markup, $charset);
            }
            $this->documentCreate($charset);
            @$this->document->loadHTML($markup);
            if ($return) {
                $this->root = $this->document;
            }
        }
        if ($return && !$this->contentType) {
            $this->contentType = 'text/html';
        }

        return $return;
    }

    protected function loadMarkupXML($markup, $requestedCharset = null)
    {
        $this->loadMarkupReset();
        $this->isXML = true;
        // check against XHTML in contentType or markup
        $isContentTypeXHTML = $this->isXHTML();
        $isMarkupXHTML = $this->isXHTML($markup);
        if ($isContentTypeXHTML || $isMarkupXHTML) {
            $this->isXHTML = true;
        }
        // determine document fragment
        if (!isset($this->isDocumentFragment)) {
            $this->isDocumentFragment = $this->isXHTML
                ? self::isDocumentFragmentXHTML($markup)
                : self::isDocumentFragmentXML($markup);
        }
        $charset = null;
        $documentCharset = $this->charsetFromXML($markup);
        if (!$documentCharset) {
            if ($this->isXHTML) {
                $documentCharset = $this->charsetFromHTML($markup);
                if ($documentCharset) {
                    $markup = $this->charsetAppendToXML($markup, $documentCharset);
                    $charset = $documentCharset;
                }
            }
            if (!$documentCharset) {
                $charset = $requestedCharset;
            }
        } elseif ($requestedCharset) {
            $charset = $requestedCharset;
        }
        if (!$charset) {
            $charset = phpQuery::$defaultCharset;
        }
        $return = false;
        if ($this->isDocumentFragment) {
            $return = $this->documentFragmentLoadMarkup($this, $charset, $markup);
        } else {
            if ($isContentTypeXHTML && !$isMarkupXHTML) {
                if (!$documentCharset) {
                    $markup = $this->charsetAppendToXML($markup, $charset);
                }
            }
            $this->documentCreate($charset);
            if (phpversion() < 5.1) {
                $this->document->resolveExternals = true;
                @$this->document->loadXML($markup);
            } else {
                /** @link http://pl2.php.net/manual/en/libxml.constants.php */
                $libxmlStatic = LIBXML_DTDLOAD | LIBXML_DTDATTR | LIBXML_NONET | LIBXML_NOWARNING | LIBXML_NOERROR;
                $return = $this->document->loadXML($markup, $libxmlStatic);
            }
            if ($return) {
                $this->root = $this->document;
            }
        }
        if ($return) {
            if (!$this->contentType) {
                if ($this->isXHTML) {
                    $this->contentType = 'application/xhtml+xml';
                } else {
                    $this->contentType = 'text/xml';
                }
            }

            return $return;
        } else {
            throw new Exception('Error loading XML markup');
        }
    }

    protected function isXHTML(?string $markup = null): bool
    {
        if (!isset($markup)) {
            return str_contains($this->contentType, 'xhtml');
        }

        return str_contains($markup, '<!DOCTYPE html');
    }

    protected function isXML(string $markup): bool
    {
        return str_contains(substr($markup, 0, 100), '<' . '?xml');
    }

    protected function contentTypeToArray($contentType)
    {
        $matches = explode(';', trim(strtolower($contentType)));
        if (isset($matches[1])) {
            $matches[1] = explode('=', $matches[1]);
            // strip 'charset='
            $matches[1] = isset($matches[1][1]) && trim($matches[1][1])
                ? $matches[1][1]
                : $matches[1][0];
        } else {
            $matches[1] = null;
        }

        return $matches;
    }

    /**
     * @param $markup
     * @return array contentType, charset
     */
    protected function contentTypeFromHTML($markup)
    {
        $matches = [];
        // find meta tag
        preg_match(
            '@<meta[^>]+http-equiv\\s*=\\s*(["|\'])Content-Type\\1([^>]+?)>@i',
            $markup,
            $matches
        );
        if (!isset($matches[0])) {
            return [null, null];
        }

        preg_match('@content\\s*=\\s*(["|\'])(.+?)\\1@', $matches[0], $matches);
        if (!isset($matches[0])) {
            return [null, null];
        }

        return $this->contentTypeToArray($matches[2]);
    }

    protected function charsetFromHTML($markup)
    {
        $contentType = $this->contentTypeFromHTML($markup);

        return $contentType[1];
    }

    protected function charsetFromXML($markup)
    {

        // find declaration
        preg_match(
            '@<' . '?xml[^>]+encoding\\s*=\\s*(["|\'])(.*?)\\1@i',
            $markup,
            $matches
        );

        return isset($matches[2])
            ? strtolower($matches[2])
            : null;
    }

    /**
     * Repositions meta[type=charset] at the start of head. Bypasses DOMDocument bug.
     *
     * @link http://code.google.com/p/phpquery/issues/detail?id=80
     * @param $html
     */
    protected function charsetFixHTML($markup)
    {
        $matches = [];
        // find meta tag
        preg_match(
            '@\s*<meta[^>]+http-equiv\\s*=\\s*(["|\'])Content-Type\\1([^>]+?)>@i',
            $markup,
            $matches,
            PREG_OFFSET_CAPTURE
        );
        if (!isset($matches[0])) {
            return '';
        }

        $metaContentType = $matches[0][0];
        $markup =
            substr($markup, 0, $matches[0][1]) .
            substr($markup, $matches[0][1] + strlen($metaContentType));
        $headStart = stripos($markup, '<head>');

        return
            substr($markup, 0, $headStart + 6) . $metaContentType .
            substr($markup, $headStart + 6);
    }

    protected function charsetAppendToHTML($html, $charset, $xhtml = false)
    {
        $html = preg_replace('@\s*<meta[^>]+http-equiv\\s*=\\s*(["|\'])Content-Type\\1([^>]+?)>@i', '', $html);
        $meta = '<meta http-equiv="Content-Type" content="text/html;charset='
            . $charset . '" '
            . ($xhtml ? '/' : '')
            . '>';
        if (!str_contains($html, '<head')) {
            if (!str_contains($html, '<html')) {
                return $meta . $html;
            } else {
                return preg_replace(
                    '@<html(.*?)(?(?<!\?)>)@s',
                    "<html\\1><head>{$meta}</head>",
                    $html
                );
            }
        } else {
            return preg_replace(
                '@<head(.*?)(?(?<!\?)>)@s',
                '<head\\1>' . $meta,
                $html
            );
        }
    }

    protected function charsetAppendToXML($markup, $charset)
    {
        return '<?xml version="1.0" encoding="' . $charset . '"?>' . $markup;
    }

    public static function isDocumentFragmentHTML($markup)
    {
        return stripos($markup, '<html') === false && stripos($markup, '<!doctype') === false;
    }

    public static function isDocumentFragmentXML($markup)
    {
        return stripos($markup, '<' . '?xml') === false;
    }

    public static function isDocumentFragmentXHTML($markup)
    {
        return self::isDocumentFragmentHTML($markup);
    }

    public function importAttr($value)
    {
    }

    /**
     * @param $source
     * @param $target
     * @param $sourceCharset
     * @return array Array of imported nodes.
     */
    public function import($source, $sourceCharset = null)
    {
        $return = [];
        if ($source instanceof DOMNode && !($source instanceof DOMNodeList)) {
            $source = [$source];
        }

        if (is_array($source) || $source instanceof DOMNodeList) {
            foreach ($source as $node) {
                $return[] = $this->document->importNode($node, true);
            }
        } else {
            $fake = $this->documentFragmentCreate($source, $sourceCharset);
            if ($fake === false) {
                throw new Exception('Error loading documentFragment markup');
            } else {
                return $this->import($fake->root->childNodes);
            }
        }

        return $return;
    }

    protected function documentFragmentCreate($source, $charset = null)
    {
        $fake = new DOMDocumentWrapper();
        $fake->contentType = $this->contentType;
        $fake->isXML = $this->isXML;
        $fake->isHTML = $this->isHTML;
        $fake->isXHTML = $this->isXHTML;
        $fake->root = $fake->document;
        if (!$charset) {
            $charset = $this->charset;
        }
        if ($source instanceof DOMNode && !($source instanceof DOMNodeList)) {
            $source = [$source];
        }
        if (is_array($source) || $source instanceof DOMNodeList) {
            if (!$this->documentFragmentLoadMarkup($fake, $charset)) {
                return false;
            }
            $nodes = $fake->import($source);
            foreach ($nodes as $node) {
                $fake->root->appendChild($node);
            }
        } else {
            $this->documentFragmentLoadMarkup($fake, $charset, $source);
        }

        return $fake;
    }

    private function documentFragmentLoadMarkup($fragment, $charset, $markup = null)
    {
        $fragment->isDocumentFragment = false;
        if ($fragment->isXML) {
            if ($fragment->isXHTML) {
                $fragment->loadMarkupXML('<?xml version="1.0" encoding="' . $charset . '"?>'
                    . '<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" '
                    . '"http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">'
                    . '<fake xmlns="http://www.w3.org/1999/xhtml">' . $markup . '</fake>');
                $fragment->root = $fragment->document->firstChild->nextSibling;
            } else {
                $fragment->loadMarkupXML('<?xml version="1.0" encoding="' . $charset . '"?><fake>' . $markup . '</fake>');
                $fragment->root = $fragment->document->firstChild;
            }
        } else {
            $markup2 = phpQuery::$defaultDoctype . '<html><head><meta http-equiv="Content-Type" content="text/html;charset='
                . $charset . '"></head>';
            $noBody = !str_contains($markup, '<body');
            if ($noBody) {
                $markup2 .= '<body>';
            }
            $markup2 .= $markup;
            if ($noBody) {
                $markup2 .= '</body>';
            }
            $markup2 .= '</html>';
            $fragment->loadMarkupHTML($markup2);
            $fragment->root = $fragment->document->firstChild->nextSibling->firstChild->nextSibling;
        }
        if (!$fragment->root) {
            return false;
        }
        $fragment->isDocumentFragment = true;

        return true;
    }

    protected function documentFragmentToMarkup($fragment)
    {
        $tmp = $fragment->isDocumentFragment;
        $fragment->isDocumentFragment = false;
        $markup = $fragment->markup();
        if ($fragment->isXML) {
            $markup = substr($markup, 0, strrpos($markup, '</fake>'));
            if ($fragment->isXHTML) {
                $markup = substr($markup, strpos($markup, '<fake') + 43);
            } else {
                $markup = substr($markup, strpos($markup, '<fake>') + 6);
            }
        } else {
            $markup = substr($markup, strpos($markup, '<body>') + 6);
            $markup = substr($markup, 0, strrpos($markup, '</body>'));
        }
        $fragment->isDocumentFragment = $tmp;

        return $markup;
    }

    /**
     * Return document markup, starting with optional $nodes as root.
     *
     * @param $nodes	DOMNode|DOMNodeList
     * @return string
     */
    public function markup($nodes = null, $innerMarkup = false)
    {
        if (isset($nodes) && count($nodes) == 1 && $nodes[0] instanceof DOMDocument) {
            $nodes = null;
        }
        if (isset($nodes)) {
            $markup = '';
            if (!is_array($nodes) && !($nodes instanceof DOMNodeList)) {
                $nodes = [$nodes];
            }
            if ($this->isDocumentFragment && !$innerMarkup) {
                foreach ($nodes as $i => $node) {
                    if ($node->isSameNode($this->root)) {
                        //	var_dump($node);
                        $nodes = array_slice($nodes, 0, $i)
                            + phpQuery::DOMNodeListToArray($node->childNodes)
                            + array_slice($nodes, $i + 1);
                    }
                }
            }
            if ($this->isXML && !$innerMarkup) {

                // we need outerXML, so we can benefit from
                // $node param support in saveXML()
                foreach ($nodes as $node) {
                    $markup .= $this->document->saveXML($node);
                }
            } else {
                $loop = [];
                if ($innerMarkup) {
                    foreach ($nodes as $node) {
                        if ($node->childNodes) {
                            foreach ($node->childNodes as $child) {
                                $loop[] = $child;
                            }
                        } else {
                            $loop[] = $node;
                        }
                    }
                } else {
                    $loop = $nodes;
                }

                $fake = $this->documentFragmentCreate($loop);
                $markup = $this->documentFragmentToMarkup($fake);
            }
            if ($this->isXHTML) {
                $markup = self::markupFixXHTML($markup);
            }

            return $markup;
        } else {
            if ($this->isDocumentFragment) {
                return $this->documentFragmentToMarkup($this);
            } else {
                $markup = $this->isXML
                    ? $this->document->saveXML()
                    : $this->document->saveHTML();
                if ($this->isXHTML) {
                    $markup = self::markupFixXHTML($markup);
                }

                return $markup;
            }
        }
    }

    protected static function markupFixXHTML($markup)
    {
        $markup = self::expandEmptyTag('script', $markup);
        $markup = self::expandEmptyTag('select', $markup);
        $markup = self::expandEmptyTag('textarea', $markup);

        return $markup;
    }

    /**
     * expandEmptyTag.
     *
     * @param $tag
     * @param $xml
     * @author mjaque at ilkebenson dot com
     * @link http://php.net/manual/en/DOMDocument.savehtml.php#81256
     */
    public static function expandEmptyTag($tag, $xml)
    {
        $indice = 0;
        while ($indice < strlen($xml)) {
            $pos = strpos($xml, "<$tag ", $indice);
            if ($pos) {
                $posCierre = strpos($xml, '>', $pos);
                if ($xml[$posCierre - 1] == '/') {
                    $xml = substr_replace($xml, "></$tag>", $posCierre - 1, 2);
                }
                $indice = $posCierre;
            } else {
                break;
            }
        }

        return $xml;
    }
}
