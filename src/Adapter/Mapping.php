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
            foreach ($associations['local'] as $association) {
                $assocSelection = $association->getTargetSelectionUnampped();

                if ($association instanceof Association\ManyToMany) {
                    // M:N
                    $joinKey = $association->getJoinKey();
                    $refKey = $association->getReferencingKey();
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
                    continue;

                } elseif ($association instanceof Association\OneToMany) {
                    // 1:N
                    $refKey = $association->getReferencedKey();
                } else {
                    // N:1
                    // 1:1
                    $refKey = $association->getReferencingKey();
                }

                $selection = \UniMapper\Entity\Selection::mergeArrays(
                    $selection,
                    [
                        $refKey => [$refKey => $assocSelection]
                    ]
                );
            }
        }

        return $selection;
    }

}