<?php

namespace UniMapper\Flexibee\Adapter;

use UniMapper\Association;
use UniMapper\Entity\Reflection;
use UniMapper\Entity\Reflection\Property;

class Mapping extends \UniMapper\Adapter\Mapping
{

    /** @var array */
    public static $format = [
        Reflection\Property::TYPE_DATE => "Y-m-d",
        Reflection\Property::TYPE_DATETIME => "Y-m-d\TH:i:sP"
    ];

    public function mapValue(Reflection\Property $property, $value)
    {
        if ($property->hasOption(Reflection\Property\Option\Assoc::KEY)
            && $property->getOption(Reflection\Property\Option\Assoc::KEY) instanceof Association\ManyToOne
            && !empty($value)
        ) {
            return $value[0];
        }

        return $value;
    }

    public function unmapValue(Reflection\Property $property, $value)
    {
        if ($value === null) {
            return "";
        } elseif ($value instanceof \DateTimeInterface
            && isset(self::$format[$property->getType()])
        ) {

            $value = $value->format(self::$format[$property->getType()]);
            if ($value === false) {
                throw new \Exception("Can not convert DateTime automatically!");
            }
        }

        return $value;
    }

    /**
     * Unmap selection
     *
     * @param \UniMapper\Entity\Reflection                         $reflection   Entity reflection
     * @param array                                                $selection    Selection array
     * @param \UniMapper\Entity\Reflection\Property\Option\Assoc[] $associations Optional associations
     * @param \UniMapper\Mapper                                    $mapper       Mapper instance
     *
     * @return array
     */
    public function unmapSelection(Reflection $reflection, array $selection, array $associations = [], \UniMapper\Mapper $mapper)
    {
        if ($associations) {
            foreach ($associations as $propertyName => $association) {
                if ($association->isRemote()) {
                    continue;
                }
                $assocSelection = $selection[$propertyName];
                unset($selection[$propertyName]);
                switch ($association->getType()) {
                    case "m:n":
                    case "m>n":
                    case "m<n":
                        list($joinKey, $joinResource, $refKey) = $association->getBy();
                        $relationTypeColumn = $joinKey === 'uzivatelske-vazby' ? 'vazbaTyp' : 'typVazbyK';
                        $selection = \UniMapper\Entity\Selection::mergeArrays(
                            $selection,
                            [
                                $joinKey => [ // uzivatelske-vazby
                                    $joinKey => [ // uzivatelske-vazby
                                        $relationTypeColumn => $relationTypeColumn, // typVazbyK or vazbaTyp
                                        $refKey => [$refKey => $assocSelection] // object => [object => ['id','kod',...]]
                                    ]
                                ]
                            ]
                        );
                        break;
                    case "1:n":
                    case "1:1":
                    case "n:1":
                        list($refKey) = $association->getBy();
                        $selection = \UniMapper\Entity\Selection::mergeArrays(
                            $selection,
                            [
                                $refKey => [$refKey => $assocSelection]
                            ]
                        );
                        break;
                    default:
                        throw new InvalidArgumentException(
                            "Unsupported association " . $association->getType() . "!",
                            $association
                        );
                }
            }
        }

        return $selection;
    }

}