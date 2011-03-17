<?php
declare(ENCODING = 'utf-8') ;
namespace F3\FLOW3\Persistence\Doctrine\Mapping\Driver;

/*                                                                        *
 * This script belongs to the FLOW3 framework.                            *
 *                                                                        *
 * It is free software; you can redistribute it and/or modify it under    *
 * the terms of the GNU Lesser General Public License as published by the *
 * Free Software Foundation, either version 3 of the License, or (at your *
 * option) any later version.                                             *
 *                                                                        *
 * This script is distributed in the hope that it will be useful, but     *
 * WITHOUT ANY WARRANTY; without even the implied warranty of MERCHAN-    *
 * TABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the GNU Lesser       *
 * General Public License for more details.                               *
 *                                                                        *
 * You should have received a copy of the GNU Lesser General Public       *
 * License along with the script.                                         *
 * If not, see http://www.gnu.org/licenses/lgpl.html                      *
 *                                                                        *
 * The TYPO3 project - inspiring people to share!                         *
 *                                                                        */

/**
 * This driver reads the mapping metadata from docblock annotations.
 * It gives precedence to Doctrine annotations but fills gaps from other info
 * if possible:
 *  Entity.repositoryClass is set to the repository found in the class schema
 *  Table.name is set to a sane value
 *  Column.type is set to @var type
 *  *.targetEntity is set to @var type
 *
 * If a property is not marked as an association the mapping type is set to
 * "object" for objects.
 *
 * @license http://www.gnu.org/licenses/lgpl.html GNU Lesser General Public License, version 3 or later
 * @scope prototype
 */
class Flow3AnnotationDriver implements \Doctrine\ORM\Mapping\Driver\Driver {

	/**
	 * @var \F3\FLOW3\Reflection\ReflectionService
	 * @inject
	 */
	protected $reflectionService;

	/**
	 * @var \Doctrine\Common\Annotations\AnnotationReader
	 */
	protected $reader;

	/**
	 * @param array
	 */
	protected $classNames;

	/**
	 * Initializes a new AnnotationDriver that uses the given AnnotationReader for reading
	 * docblock annotations.
	 */
	public function __construct() {
		$this->reader = new \Doctrine\Common\Annotations\AnnotationReader();
		$this->reader->setDefaultAnnotationNamespace('Doctrine\ORM\Mapping\\');
	}

	/**
	 * Fetch a class schema for the given class, if possible.
	 *
	 * @param string $className
	 * @return \F3\FLOW3\Reflection\ClassSchema
	 * @throws \RuntimeException
	 */
	protected function getClassSchema($className) {
		if (strpos($className, '_Original') !== FALSE) {
			$className = substr($className, 0, strrpos($className, '_Original'));
		}
		$classSchema = $this->reflectionService->getClassSchema($className);
		if (!$classSchema) {
			throw new \RuntimeException('No class schema found for "' . $className . '"', 1295973082);
		}
		return $classSchema;
	}

	/**
	 * Loads the metadata for the specified class into the provided container.
	 *
	 * @param string $className
	 * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
	 * @todo adjust when Doctrine 2 supports value objects
	 * @return void
	 */
	public function loadMetadataForClass($className, \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata) {
		$class = $metadata->getReflectionClass();
		$classSchema = $this->getClassSchema($class->getName());
		$classAnnotations = $this->reader->getClassAnnotations($class);

			// Evaluate Entity annotation
		if (isset($classAnnotations['Doctrine\ORM\Mapping\MappedSuperclass'])) {
			$metadata->isMappedSuperclass = TRUE;
		} elseif (isset($classAnnotations['Doctrine\ORM\Mapping\Entity'])) {
			$entityAnnotation = $classAnnotations['Doctrine\ORM\Mapping\Entity'];
			if ($entityAnnotation->repositoryClass) {
				$metadata->setCustomRepositoryClass($entityAnnotation->repositoryClass);
			} elseif ($classSchema->getRepositoryClassName() !== NULL) {
				if ($this->reflectionService->isClassImplementationOf($classSchema->getRepositoryClassName(), 'Doctrine\ORM\EntityRepository')) {
					$metadata->setCustomRepositoryClass($classSchema->getRepositoryClassName());
				}
			}
		} elseif ($classSchema->getModelType() === \F3\FLOW3\Reflection\ClassSchema::MODELTYPE_VALUEOBJECT) {
				// also ok...
		} else {
			throw \Doctrine\ORM\Mapping\MappingException::classIsNotAValidEntityOrMappedSuperClass($className);
		}

			// Evaluate Table annotation
		if (isset($classAnnotations['Doctrine\ORM\Mapping\Table'])) {
			$tableAnnotation = $classAnnotations['Doctrine\ORM\Mapping\Table'];
			$primaryTable = array(
				'name' => $tableAnnotation->name,
				'schema' => $tableAnnotation->schema
			);

			if ($tableAnnotation->indexes !== null) {
				foreach ($tableAnnotation->indexes as $indexAnnotation) {
					$primaryTable['indexes'][$indexAnnotation->name] = array(
						'columns' => $indexAnnotation->columns
					);
				}
			}

			if ($tableAnnotation->uniqueConstraints !== null) {
				foreach ($tableAnnotation->uniqueConstraints as $uniqueConstraint) {
					$primaryTable['uniqueConstraints'][$uniqueConstraint->name] = array(
						'columns' => $uniqueConstraint->columns
					);
				}
			}

			$metadata->setPrimaryTable($primaryTable);
		} else {
			$className = $classSchema->getClassName();
			$primaryTable = array('name' => strtolower(substr($className, strrpos($className, '\\')+1)));
#			$idProperties = array_keys($classSchema->getIdentityProperties());
#			$primaryTable['uniqueConstraints']['flow3_identifier'] = array(
#				'columns' => $idProperties
#			);
			$metadata->setPrimaryTable($primaryTable);
		}

			// Evaluate InheritanceType annotation
		if (isset($classAnnotations['Doctrine\ORM\Mapping\InheritanceType'])) {
			$inheritanceTypeAnnotation = $classAnnotations['Doctrine\ORM\Mapping\InheritanceType'];
			$metadata->setInheritanceType(constant('Doctrine\ORM\Mapping\ClassMetadata::INHERITANCE_TYPE_' . $inheritanceTypeAnnotation->value));

			if ($metadata->inheritanceType != \Doctrine\ORM\Mapping\ClassMetadata::INHERITANCE_TYPE_NONE) {
					// Evaluate DiscriminatorColumn annotation
				if (isset($classAnnotations['Doctrine\ORM\Mapping\DiscriminatorColumn'])) {
					$discrColumnAnnotation = $classAnnotations['Doctrine\ORM\Mapping\DiscriminatorColumn'];
					$metadata->setDiscriminatorColumn(array(
						'name' => $discrColumnAnnotation->name,
						'type' => $discrColumnAnnotation->type,
						'length' => $discrColumnAnnotation->length
					));
				} else {
					$metadata->setDiscriminatorColumn(array('name' => 'dtype', 'type' => 'string', 'length' => 255));
				}

					// Evaluate DiscriminatorMap annotation
				if (isset($classAnnotations['Doctrine\ORM\Mapping\DiscriminatorMap'])) {
					$discriminatorMapAnnotation = $classAnnotations['Doctrine\ORM\Mapping\DiscriminatorMap'];
					$metadata->setDiscriminatorMap($discriminatorMapAnnotation->value);
				} else {
					$discriminatorMap = array();
					$subclassNames = $this->reflectionService->getAllSubClassNamesForClass($className);
					foreach ($subclassNames as $subclassName) {
						$mappedSubclassName = strtolower(str_replace('Domain_Model_', '', str_replace('\\', '_', $subclassName)));
						$discriminatorMap[$mappedSubclassName] = $subclassName;
					}
					$metadata->setDiscriminatorMap($discriminatorMap);
				}
			}
		}


			// Evaluate DoctrineChangeTrackingPolicy annotation
		if (isset($classAnnotations['Doctrine\ORM\Mapping\ChangeTrackingPolicy'])) {
			$changeTrackingAnnotation = $classAnnotations['Doctrine\ORM\Mapping\ChangeTrackingPolicy'];
			$metadata->setChangeTrackingPolicy(constant('Doctrine\ORM\Mapping\ClassMetadata::CHANGETRACKING_' . $changeTrackingAnnotation->value));
		}

			// Evaluate annotations on properties/fields
		$this->evaluatePropertyAnnotations($metadata);

			// Evaluate @HasLifecycleCallbacks annotation
		$this->evaluateLifeCycleAnnotations($classAnnotations, $class, $metadata);
	}

	/**
	 * Evaluate the property annotations and amend the metadata accordingly.
	 *
	 * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
	 * @return void
	 */
	protected function evaluatePropertyAnnotations(\Doctrine\ORM\Mapping\ClassMetadataInfo $metadata) {
		$className = $metadata->name;

		$class = $metadata->getReflectionClass();
		$classSchema = $this->getClassSchema($className);

		foreach ($class->getProperties() as $property) {
			if (!$classSchema->hasProperty($property->getName())
					||
					$metadata->isMappedSuperclass && !$property->isPrivate()
					||
					$metadata->isInheritedField($property->getName())
					||
					$metadata->isInheritedAssociation($property->getName())) {
				continue;
			}

			$data = $classSchema->getProperty($property->getName());

			$mapping = array();
			$mapping['fieldName'] = $property->getName();

				// Check for JoinColummn/JoinColumns annotations
			$joinColumns = array();
			if ($joinColumnAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\JoinColumn')) {
				$joinColumns[] = array(
					'name' => $joinColumnAnnotation->name,
					'referencedColumnName' => $joinColumnAnnotation->referencedColumnName,
					'unique' => $joinColumnAnnotation->unique,
					'nullable' => $joinColumnAnnotation->nullable,
					'onDelete' => $joinColumnAnnotation->onDelete,
					'onUpdate' => $joinColumnAnnotation->onUpdate,
					'columnDefinition' => $joinColumnAnnotation->columnDefinition,
				);
			} else if ($joinColumnsAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\JoinColumns')) {
				foreach ($joinColumnsAnnotation->value as $joinColumn) {
					$joinColumns[] = array(
						'name' => $joinColumn->name,
						'referencedColumnName' => $joinColumn->referencedColumnName,
						'unique' => $joinColumn->unique,
						'nullable' => $joinColumn->nullable,
						'onDelete' => $joinColumn->onDelete,
						'onUpdate' => $joinColumn->onUpdate,
						'columnDefinition' => $joinColumn->columnDefinition,
					);
				}
			}

				// Field can only be annotated with one of:
				// @OneToOne, @OneToMany, @ManyToOne, @ManyToMany, @Column (optional)
			if ($oneToOneAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\OneToOne')) {
				if ($oneToOneAnnotation->targetEntity) {
					$mapping['targetEntity'] = $oneToOneAnnotation->targetEntity;
				} else {
					$mapping['targetEntity'] = $data['type'];
				}
				$mapping['joinColumns'] = $joinColumns;
				$mapping['mappedBy'] = $oneToOneAnnotation->mappedBy;
				$mapping['inversedBy'] = $oneToOneAnnotation->inversedBy;
				$mapping['cascade'] = $oneToOneAnnotation->cascade;
				$mapping['orphanRemoval'] = $oneToOneAnnotation->orphanRemoval;
				$mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $oneToOneAnnotation->fetch);
				$metadata->mapOneToOne($mapping);
			} elseif ($oneToManyAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\OneToMany')) {
				$mapping['mappedBy'] = $oneToManyAnnotation->mappedBy;
				if ($oneToManyAnnotation->targetEntity) {
					$mapping['targetEntity'] = $oneToManyAnnotation->targetEntity;
				} else {
					$mapping['targetEntity'] = $data['elementType'];
				}
				$mapping['cascade'] = $oneToManyAnnotation->cascade;
				$mapping['orphanRemoval'] = $oneToManyAnnotation->orphanRemoval;
				$mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $oneToManyAnnotation->fetch);

				if ($orderByAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\OrderBy')) {
					$mapping['orderBy'] = $orderByAnnotation->value;
				}

				$metadata->mapOneToMany($mapping);
			} elseif ($manyToOneAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\ManyToOne')) {
				$mapping['joinColumns'] = $joinColumns;
				$mapping['cascade'] = $manyToOneAnnotation->cascade;
				$mapping['inversedBy'] = $manyToOneAnnotation->inversedBy;
				if ($manyToOneAnnotation->targetEntity) {
					$mapping['targetEntity'] = $manyToOneAnnotation->targetEntity;
				} else {
					$mapping['targetEntity'] = $data['type'];
				}
				$mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $manyToOneAnnotation->fetch);
				$metadata->mapManyToOne($mapping);
			} elseif ($manyToManyAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\ManyToMany')) {
				$joinTable = array();

				if ($joinTableAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\JoinTable')) {
					$joinTable = array(
						'name' => $joinTableAnnotation->name,
						'schema' => $joinTableAnnotation->schema
					);

					foreach ($joinTableAnnotation->joinColumns as $joinColumn) {
						$joinTable['joinColumns'][] = array(
							'name' => $joinColumn->name,
							'referencedColumnName' => $joinColumn->referencedColumnName,
							'unique' => $joinColumn->unique,
							'nullable' => $joinColumn->nullable,
							'onDelete' => $joinColumn->onDelete,
							'onUpdate' => $joinColumn->onUpdate,
							'columnDefinition' => $joinColumn->columnDefinition,
						);
					}

					foreach ($joinTableAnnotation->inverseJoinColumns as $joinColumn) {
						$joinTable['inverseJoinColumns'][] = array(
							'name' => $joinColumn->name,
							'referencedColumnName' => $joinColumn->referencedColumnName,
							'unique' => $joinColumn->unique,
							'nullable' => $joinColumn->nullable,
							'onDelete' => $joinColumn->onDelete,
							'onUpdate' => $joinColumn->onUpdate,
							'columnDefinition' => $joinColumn->columnDefinition,
						);
					}
				}

				$mapping['joinTable'] = $joinTable;
				if ($manyToManyAnnotation->targetEntity) {
					$mapping['targetEntity'] = $manyToManyAnnotation->targetEntity;
				} else {
					$mapping['targetEntity'] = $data['elementType'];
				}
				$mapping['mappedBy'] = $manyToManyAnnotation->mappedBy;
				$mapping['inversedBy'] = $manyToManyAnnotation->inversedBy;
				$mapping['cascade'] = $manyToManyAnnotation->cascade;
				$mapping['fetch'] = constant('Doctrine\ORM\Mapping\ClassMetadata::FETCH_' . $manyToManyAnnotation->fetch);

				if ($orderByAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\OrderBy')) {
					$mapping['orderBy'] = $orderByAnnotation->value;
				}

				$metadata->mapManyToMany($mapping);
			} else {
				$mapping['nullable'] = TRUE;

				if ($columnAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\Column')) {
					$mapping['type'] = $columnAnnotation->type;
					$mapping['length'] = $columnAnnotation->length;
					$mapping['precision'] = $columnAnnotation->precision;
					$mapping['scale'] = $columnAnnotation->scale;
					$mapping['nullable'] = $columnAnnotation->nullable;
					$mapping['unique'] = $columnAnnotation->unique;
					if ($columnAnnotation->options) {
						$mapping['options'] = $columnAnnotation->options;
					}

					if (isset($columnAnnotation->name)) {
						$mapping['columnName'] = $columnAnnotation->name;
					}

					if (isset($columnAnnotation->columnDefinition)) {
						$mapping['columnDefinition'] = $columnAnnotation->columnDefinition;
					}
				}

				if (!isset($mapping['type'])) {
					switch ($data['type']) {
						case 'DateTime':
							$mapping['type'] = 'datetime';
							break;
						case 'string':
						case 'integer':
						case 'boolean':
						case 'float':
						case 'array':
							$mapping['type'] = $data['type'];
							break;
						default:
							if (strpos($data['type'], '\\') !== FALSE) {
								if ($this->reflectionService->isClassTaggedWith($data['type'], 'valueobject')) {
									$mapping['type'] = 'object';
								}
							} else {
								\Doctrine\ORM\Mapping\MappingException::propertyTypeIsRequired($className, $property->getName());
							}
					}

				}

				if ($idAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\Id')) {
					$mapping['id'] = true;
				}

				if ($generatedValueAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\GeneratedValue')) {
					$metadata->setIdGeneratorType(constant('Doctrine\ORM\Mapping\ClassMetadata::GENERATOR_TYPE_' . $generatedValueAnnotation->strategy));
				}

				if ($this->reflectionService->isPropertyTaggedWith($className, $property->getName(), 'version')
						|| $this->reflectionService->isPropertyTaggedWith($className, $property->getName(), 'Version')) {
					$metadata->setVersionMapping($mapping);
				}

				$metadata->mapField($mapping);

					// Check for SequenceGenerator/TableGenerator definition
				if ($seqGeneratorAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\SequenceGenerator')) {
					$metadata->setSequenceGeneratorDefinition(array(
						'sequenceName' => $seqGeneratorAnnotation->sequenceName,
						'allocationSize' => $seqGeneratorAnnotation->allocationSize,
						'initialValue' => $seqGeneratorAnnotation->initialValue
					));
				} else if ($tblGeneratorAnnotation = $this->reader->getPropertyAnnotation($property, 'Doctrine\ORM\Mapping\TableGenerator')) {
					throw \Doctrine\ORM\Mapping\MappingException::tableIdGeneratorNotImplemented($className);
				}
			}

		}
	}

	/**
	 * Evaluate the lifecycle annotations and amend the metadata accordingly.
	 *
	 * @param array $classAnnotations
	 * @param \ReflectionClass $class
	 * @param \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata
	 * @return void
	 */
	protected function evaluateLifeCycleAnnotations(array $classAnnotations, \ReflectionClass $class, \Doctrine\ORM\Mapping\ClassMetadataInfo $metadata) {
		if (isset($classAnnotations['Doctrine\ORM\Mapping\HasLifecycleCallbacks'])) {
			foreach ($class->getMethods() as $method) {
				if ($method->isPublic()) {
					$annotations = $this->reader->getMethodAnnotations($method);

					if (isset($annotations['Doctrine\ORM\Mapping\PrePersist'])) {
						$metadata->addLifecycleCallback($method->getName(), \Doctrine\ORM\Events::prePersist);
					}

					if (isset($annotations['Doctrine\ORM\Mapping\PostPersist'])) {
						$metadata->addLifecycleCallback($method->getName(), \Doctrine\ORM\Events::postPersist);
					}

					if (isset($annotations['Doctrine\ORM\Mapping\PreUpdate'])) {
						$metadata->addLifecycleCallback($method->getName(), \Doctrine\ORM\Events::preUpdate);
					}

					if (isset($annotations['Doctrine\ORM\Mapping\PostUpdate'])) {
						$metadata->addLifecycleCallback($method->getName(), \Doctrine\ORM\Events::postUpdate);
					}

					if (isset($annotations['Doctrine\ORM\Mapping\PreRemove'])) {
						$metadata->addLifecycleCallback($method->getName(), \Doctrine\ORM\Events::preRemove);
					}

					if (isset($annotations['Doctrine\ORM\Mapping\PostRemove'])) {
						$metadata->addLifecycleCallback($method->getName(), \Doctrine\ORM\Events::postRemove);
					}

					if (isset($annotations['Doctrine\ORM\Mapping\PostLoad'])) {
						$metadata->addLifecycleCallback($method->getName(), \Doctrine\ORM\Events::postLoad);
					}
				}
			}
		}
	}

	/**
	 * Returns the names of all mapped (non-transient) classes known to this driver.
	 *
	 * @return array
	 */
	public function getAllClassNames() {
		if (is_array($this->classNames)) {
			return $this->classNames;
		}

		$this->classNames = array_merge(
			$this->reflectionService->getClassNamesByTag('valueobject'),
			$this->reflectionService->getClassNamesByTag('entity'),
			$this->reflectionService->getClassNamesByTag('Entity'),
			$this->reflectionService->getClassNamesByTag('MappedSuperclass')
		);
		$this->classNames = array_filter($this->classNames,
			function ($className) {
				return !interface_exists($className, FALSE)
						&& strpos($className, '_Original') === FALSE;
			}
		);

		return $this->classNames;
	}

	/**
	 * Whether the class with the specified name should have its metadata loaded.
	 * This is only the case if it is either mapped as an Entity or a
	 * MappedSuperclass (i.e. is not transient).
	 *
	 * @param string $className
	 * @return boolean
	 */
	public function isTransient($className) {
		return strpos($className, '_Original') !== FALSE ||
				(
					!$this->reflectionService->isClassTaggedWith($className, 'valueobject') &&
					!$this->reflectionService->isClassTaggedWith($className, 'entity') &&
					!$this->reflectionService->isClassTaggedWith($className, 'Entity') &&
					!$this->reflectionService->isClassTaggedWith($className, 'MappedSuperclass')
				);
	}

}

?>