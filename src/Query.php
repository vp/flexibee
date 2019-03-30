<?php

namespace UniMapper\Flexibee;

use InvalidArgumentException;
use UniMapper\Association;
use UniMapper\Entity\Filter;
use UniMapper\Entity\Reflection\Property\Option\Assoc;

class Query implements \UniMapper\Adapter\IQuery
{

    const GET = "get",
          PUT = "put",
          DELETE = "delete";

    public $id;
    public $data;
    public $evidence;
    public $filter;
    public $method;
    public $parameters = [];
    public $resultCallback;
    public $associations = [];
    public $includes = [];
    public $relations = [];

    /** @var bool  */
    private $detailFullOnAssociations = true;

    public function __construct($evidence, $method = self::GET, array $data = [])
    {
        $this->evidence = $evidence;
        $this->method = $method;
        $this->data = $data;
    }

    /**
     * If set to true detail=full will be used
     * when associations or relations present in query
     *
     * @param bool $value
     */
    public function setDetailFullOnAssociations($value)
    {
        $this->detailFullOnAssociations = $value;
    }

    public function setFilter(array $filter)
    {
        $this->filter = $this->_formatFilter($filter);
    }

    public function setAssociations(array $associations, array $associationsFilters = [])
    {

        foreach ($associations as $propertyName => $association) {
            switch ($association->getType()) {
                case "m:n":
                case "m>n":
                case "m<n":
                    $this->_manyToMany($association);
                    break;
                case "1:n":
                    $this->_oneToMany($association);
                    break;
                case "1:1":
                case "n:1":
                    $this->_manyOrOneToOne($association);
                    break;
                default:
                    throw new InvalidArgumentException(
                        "Unsupported association " . $association->getType() . "!",
                        $association
                    );
            }
        }

        $this->associations = $associations;
    }

    private function _oneToMany(Assoc $association)
    {
        list($referencedKey) = $association->getBy();

        $this->relations[] = $referencedKey;
    }

    private function _manyOrOneToOne(Assoc $association)
    {
        list($referencingKey) = $association->getBy();

        $this->includes[] = "/" . $this->evidence . "/" . $referencingKey;
        $this->relations[] = $referencingKey; // Due to attachments
    }


    private function _manyToMany(Assoc $association)
    {
        list($joinKey, $joinResource, $referencingKey) = $association->getBy();

        if ($joinKey === "vazby") {

            $this->relations[] = "vazby";
            $this->includes[] = "/winstrom/" . $this->evidence . "/vazby/vazba/" . $referencingKey;
        } elseif ($joinKey === "uzivatelske-vazby") {

            $this->relations[] = "uzivatelske-vazby";
            $this->includes[] = "/winstrom/" . $this->evidence . "/uzivatelske-vazby/uzivatelska-vazba/object";
        } else {
            throw new \Exception("Unexpected association key on M:N!");
        }
    }


    public function getRaw()
    {
        $url = $this->evidence;

        if ($this->method === self::PUT) {
            $this->parameters["code-in-response"] = "true";
        } elseif ($this->method === self::GET) {

            $this->parameters["code-as-id"] = "true"; // @todo should be optional

            if ($this->filter) {
                $url .= "/(" . rawurlencode($this->filter) . ")";
            }
        }

        if ($this->id && $this->method !== self::PUT) {
            $url .= "/" . rawurlencode($this->id);
        }

        $url .= ".json";

        $detailFullOnAssociations = $this->detailFullOnAssociations;
        //- HACK FIX on bug in flexibee "cenHladiny" association
        if ($this->evidence === 'adresar' && in_array('cenHladiny', $this->relations)) {
            $detailFullOnAssociations = true;
        }

        // Join relations & includes
        if ($this->includes) {
            if ($detailFullOnAssociations) {
                $this->parameters["detail"] = "full";
            }
            $this->parameters["includes"] = implode(",", $this->includes);
        }
        if ($this->relations) {
            if ($detailFullOnAssociations) {
                $this->parameters["detail"] = "full";
            }
            $this->parameters["relations"] = implode(",", $this->relations);
        }

        if ($this->parameters) {
            $url .= "?" . urldecode(http_build_query($this->parameters)); // parameters are generated and validated, so dont worry and dont send big (encoded) params
        }

        return $url;
    }

    private function _formatFilter(array $filter, $or = false)
    {
        $result = "";

        if (Filter::isGroup($filter)) {
            // Filter group

            foreach ($filter as $modifier => $item) {

                $formatted = "(" .$this->_formatFilter($item, $modifier === Filter::_OR ? true : false) . ")";
                if (empty($result)) {
                    $result = $formatted;
                } else {
                    $result .= " " . ($or ? "OR" : "AND") . " " . $formatted;
                }
            }
            return "(" . $result . ")";
        } else {
            // Filter item

            foreach ($filter as $itemName => $item) {

                foreach ($item as $modifier => $value) {
                    //- because unsets below
                    $name = $itemName;

                    if ($name === "stitky") {
                        $value = explode(",", $value);
                    }

                    if ($modifier === Filter::START) {

                        $modifier = "BEGINS";
                        if (Adapter::$likeWithSimilar) {
                            $modifier .= " SIMILAR";
                        }
                        $value = "'" . $value . "'";
                    } elseif ($modifier === Filter::END) {

                        $modifier = "ENDS";
                        if (Adapter::$likeWithSimilar) {
                            $modifier .= " SIMILAR";
                        }
                        $value = "'" . $value . "'";
                    } elseif ($modifier === Filter::CONTAIN) {

                        $modifier = "LIKE";
                        if (Adapter::$likeWithSimilar) {
                            $modifier .= " SIMILAR";
                        }
                        $value = "'" . $value . "'";
                    } elseif ($modifier === Filter::NOT && is_array($value)) {
                        // NOT IN

                        foreach ($value as $index => $item) {
                            $value[$index] = $name . " != " .  (is_string($item) ? "'" .  $item . "'" : $item);
                        }

                        $value = "(" . implode(" AND ", $value) . ")";
                        unset($modifier);
                        unset($name);
                    } elseif ($modifier === Filter::EQUAL && is_array($value)) {
                        // IN

                        if ($name === "stitky") {

                            foreach ($value as $index => $item) {
                                $value[$index] = $name . " = '" .  $item . "'";
                            }
                            $value = "(" . implode(" OR ", $value) . ")";
                            unset($modifier);
                            unset($name);
                        } else {

                            $modifier = "IN";
                            if (array_filter($value, 'is_string')) {
                                $value = "('" . implode("','", $value) . "')";
                            } else {
                                $value = "(" . implode(",", $value) . ")";
                            }
                        }
                    } elseif (in_array($modifier, [Filter::EQUAL, Filter::NOT], true)) {
                        // IS, IS NOT, =, !=

                        if (is_bool($value)) {

                            if ($modifier === Filter::NOT) {
                                $value = !$value; // Flexibee does not support IS NOT with bool values
                            }
                            $modifier = "IS";
                        } elseif ($value === null || $value === "") {

                            if ($modifier === Filter::NOT) {
                                $modifier = "IS NOT";
                            } else {
                                $modifier = "IS";
                            }
                            $value = "NULL";
                        } elseif ($value === "''" || $value === '""') {

                            if ($modifier === Filter::NOT) {
                                $modifier = "IS NOT";
                            } else {
                                $modifier = "IS";
                            }
                            $value = "empty";
                        } else {

                            if ($modifier === Filter::EQUAL) {
                                $modifier = "=";
                            } else {
                                $modifier = "!=";
                            }
                            $value = is_string($value) ? "'" . $value . "'" : $value;
                        }
                    } else {
                        // Other modifiers

                        $value = is_string($value) ? "'" . $value . "'" : $value;
                    }

                    if (is_bool($value)) {
                        $value = var_export($value, true);
                    }

                    if (!empty($result)) {
                        $result .= " " . ($or ? "OR" : "AND") . " ";
                    }

                    if (isset($name)) {
                        $result .= $name . " ";
                    }
                    if (isset($modifier)) {
                        $result .= $modifier . " ";
                    }

                    $result .= $value;
                }
            }

            return $result;
        }
    }

    public function addOption($name, $value)
    {
        $this->parameters[$name] = $value;
    }

}
