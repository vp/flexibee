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
        if ($property->hasOption(Reflection\Property::OPTION_ASSOC)
            && $property->getOption(Reflection\Property::OPTION_ASSOC) instanceof Association\ManyToOne
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
        } elseif ($value instanceof \DateTime
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
     * @param \UniMapper\Entity\Reflection $reflection   Entity reflection
     * @param array                        $selection    Selection array
     * @param \UniMapper\Association[]     $associations Optional associations
     * @param \UniMapper\Mapper            $mapper       Mapper instance
     *
     * @return array
     */
    public function unmapSelection(Reflection $reflection, array $selection, array $associations = [], \UniMapper\Mapper $mapper)
    {
       if ($associations) {
            // handle local associations
            foreach ($associations as $association) {
                $targetSelection = $association->getTargetSelection();
                $targetReflection = $association->getTargetReflection();
                if (!$targetSelection) {
                    $targetSelection = \UniMapper\Entity\Selection::generateEntitySelection($targetReflection);
                }
                $targetSelection = \UniMapper\Entity\Selection::normalizeEntitySelection($targetReflection, $targetSelection);
                $assocSelection = $mapper->unmapSelection($targetReflection, $targetSelection);

                $mapBy = $association->getMapBy();
                $relationTypeColumn = $mapBy[0] === 'uzivatelske-vazby' ? 'vazbaTyp' : 'typVazbyK';
                $selection = \UniMapper\Entity\Selection::mergeArrays(
                    $selection,
                    [
                        $mapBy[0] => [ // uzivatelske-vazby
                            $mapBy[0] => [ // uzivatelske-vazby
                                $relationTypeColumn => $relationTypeColumn, // typVazbyK or vazbaTyp
                                $mapBy[2] => [$mapBy[2] => $assocSelection] // object => [object => ['id','kod',...]]
                            ]
                        ]
                    ]
                );
            }
        }

        return $selection;
    }

}