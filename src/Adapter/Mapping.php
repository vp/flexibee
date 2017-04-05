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
     *
     * @return array
     */
    public function unmapSelection(Reflection $reflection, array $selection, array $associations = [])
    {
        $selection = \UniMapper\Flexibee\Adapter::mergeArrays($selection, $this->traverseEntityForSelection($reflection, $selection));
        if ($associations) {
            foreach ($associations as $association) {
                $targetSelection = $association->getTargetSelection();
                if ($targetSelection) {
                    $assocSelection = [];
                    foreach ($targetSelection as $propertyName) {
                        $property = $reflection->getProperty($propertyName);
                        $assocSelection[$property->getName()] = $property->getName(true);
                    }
                } else {
                    $assocSelection = $this->traverseEntityForPropertySelection($association->getTargetReflection());
                }

                $mapBy = $association->getMapBy();
                $relationTypeColumn = $mapBy[0] === 'uzivatelske-vazby' ? 'vazbaTyp' : 'typVazbyK';
                $selection = \UniMapper\Flexibee\Adapter::mergeArrays(
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

    protected function traverseEntityForPropertySelection(Reflection $entityReflection)
    {
        $selection = [];
        foreach ($entityReflection->getProperties() as $property) {
            // Exclude associations & computed properties
            if (!$property->hasOption(Reflection\Property::OPTION_ASSOC)
                && !$property->hasOption(Reflection\Property::OPTION_COMPUTED)
            ) {
                if ($property->getType() !== \UniMapper\Entity\Reflection\Property::TYPE_COLLECTION
                    && $property->getType() !== \UniMapper\Entity\Reflection\Property::TYPE_ENTITY
                ) {
                    $selection[$property->getName()] = $property->getName(true);
                } else if ($property->getType() === \UniMapper\Entity\Reflection\Property::TYPE_COLLECTION) {
                    $propertyEntityReflection = Reflection::load($property->getTypeOption());
                    $selection[$property->getName()] = [
                        $property->getName(true) => $this->traverseEntityForPropertySelection($propertyEntityReflection)
                    ];
                }
            }
        }
        return $selection;
    }

    protected function traverseEntityForSelection(Reflection $entityReflection, $mainSelection = [])
    {
        $selection = [];
        foreach ($entityReflection->getProperties() as $property) {
            if (/*($property->getType() === \UniMapper\Entity\Reflection\Property::TYPE_ENTITY)
                ||*/
            ($property->getType() === \UniMapper\Entity\Reflection\Property::TYPE_COLLECTION)
            ) {
                if (!$mainSelection || in_array($property->getName(), $mainSelection) !== false) {
                    // Exclude associations & computed properties
                    if (!$property->hasOption(Property::OPTION_ASSOC)
                        && !$property->hasOption(Property::OPTION_COMPUTED)
                    ) {
                        $propertyEntityReflection = Reflection::load($property->getTypeOption());
                        $selection[$property->getName()] = [
                            $property->getName(true) => $this->traverseEntityForPropertySelection($propertyEntityReflection)
                        ];
                    }
                }
            }
        }
        return $selection;
    }

}