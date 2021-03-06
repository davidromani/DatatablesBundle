<?php

/**
 * This file is part of the SgDatatablesBundle package.
 *
 * (c) stwe <https://github.com/stwe/DatatablesBundle>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Sg\DatatablesBundle\Datatable\Data;

use Doctrine\ORM\EntityManager;
use Doctrine\ORM\Mapping\ClassMetadata;
use Doctrine\ORM\QueryBuilder;
use Doctrine\ORM\Query;

/**
 * Class DatatableQuery
 *
 * @package Sg\DatatablesBundle\Datatable\Data
 */
class DatatableQuery
{
    /**
     * @var array
     */
    protected $requestParams;

    /**
     * @var EntityManager
     */
    protected $em;

    /**
     * @var string
     */
    protected $tableName;

    /**
     * @var string
     */
    protected $entityName;

    /**
     * @var QueryBuilder
     */
    protected $qb;

    /**
     * @var array
     */
    protected $selectColumns;

    /**
     * @var array
     */
    protected $allColumns;

    /**
     * @var array
     */
    protected $joins;

    /**
     * @var array
     */
    protected $callbacks;


    //-------------------------------------------------
    // Ctor.
    //-------------------------------------------------

    /**
     * Ctor.
     *
     * @param array         $requestParams All request params
     * @param ClassMetadata $metadata      A ClassMetadata instance
     * @param EntityManager $em            A EntityManager instance
     */
    public function __construct(array $requestParams, ClassMetadata $metadata, EntityManager $em)
    {
        $this->requestParams = $requestParams;
        $this->em = $em;
        $this->tableName = $metadata->getTableName();
        $this->entityName = $metadata->getName();
        $this->qb = $this->em->createQueryBuilder();
        $this->selectColumns = array();
        $this->allColumns = array();
        $this->joins = array();
        $this->callbacks = array(
            "WhereBuilder" => array(),
        );
    }


    //-------------------------------------------------
    // Public
    //-------------------------------------------------

    /**
     * Get qb.
     *
     * @return QueryBuilder
     */
    public function getQb()
    {
        return $this->qb;
    }

    /**
     * Set selectColumns.
     *
     * @param array $selectColumns
     *
     * @return $this
     */
    public function setSelectColumns(array $selectColumns)
    {
        $this->selectColumns = $selectColumns;

        return $this;
    }

    /**
     * Set allColumns.
     *
     * @param array $allColumns
     *
     * @return $this
     */
    public function setAllColumns(array $allColumns)
    {
        $this->allColumns = $allColumns;

        return $this;
    }

    /**
     * Set joins.
     *
     * @param array $joins
     *
     * @return $this
     */
    public function setJoins(array $joins)
    {
        $this->joins = $joins;

        return $this;
    }

    /**
     * Add callback.
     *
     * @param string $callback
     *
     * @return $this
     */
    public function addCallback($callback)
    {
        $this->callbacks["WhereBuilder"][] = $callback;

        return $this;
    }

    /**
     * Query results before filtering.
     *
     * @param integer $rootEntityIdentifier
     *
     * @return int
     */
    public function getCountAllResults($rootEntityIdentifier)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select("count(" . $this->tableName . "." . $rootEntityIdentifier . ")");
        $qb->from($this->entityName, $this->tableName);

        $this->setWhereCallbacks($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Query results after filtering.
     *
     * @param integer $rootEntityIdentifier
     *
     * @return int
     */
    public function getCountFilteredResults($rootEntityIdentifier)
    {
        $qb = $this->em->createQueryBuilder();
        $qb->select("count(distinct " . $this->tableName . "." . $rootEntityIdentifier . ")");
        $qb->from($this->entityName, $this->tableName);

        $this->setLeftJoins($qb);
        $this->setWhere($qb);
        $this->setWhereCallbacks($qb);

        return (int) $qb->getQuery()->getSingleScalarResult();
    }

    /**
     * Set select from.
     *
     * @return $this
     */
    public function setSelectFrom()
    {
        foreach ($this->selectColumns as $key => $value) {
            // $qb->select('partial comment.{id, title}, partial post.{id, title}');
            $this->qb->addSelect("partial " . $key . ".{" . implode(",", $this->selectColumns[$key]) . "}");
        }

        $this->qb->from($this->entityName, $this->tableName);

        return $this;
    }

    /**
     * Set leftJoins.
     *
     * @param QueryBuilder $qb A QueryBuilder instance
     *
     * @return $this
     */
    public function setLeftJoins(QueryBuilder $qb)
    {
        foreach ($this->joins as $join) {
            $qb->leftJoin($join["source"], $join["target"]);
        }

        return $this;
    }

    /**
     * Set where statement.
     *
     * @param QueryBuilder $qb A QueryBuilder instance
     *
     * @return $this
     */
    public function setWhere(QueryBuilder $qb)
    {
        // global filtering
        if ($this->requestParams["search"]["value"] != "") {

            $orExpr = $qb->expr()->orX();

            for ($i = 0; $i <= $this->requestParams["dql_counter"]; $i++) {
                if (isset($this->requestParams["columns"][$i]["searchable"]) && $this->requestParams["columns"][$i]["searchable"] == "true") {
                    $searchField = $this->allColumns[$i];
                    $orExpr->add($qb->expr()->like($searchField, "?$i"));
                    $qb->setParameter($i, "%" . $this->requestParams["search"]["value"] . "%");
                }
            }

            $qb->where($orExpr);
        }

        //var_dump($qb->getDQL());die();

        // individual filtering
        $andExpr = $qb->expr()->andX();

        for ($i = 0; $i <= $this->requestParams["dql_counter"]; $i++) {
            if (isset($this->requestParams["columns"]["{$i}"]["searchable"]) && $this->requestParams["columns"]["{$i}"]["searchable"] === "true" && $this->requestParams["columns"]["{$i}"]["search"]["value"] != "") {
                $searchField = $this->allColumns[$i];
                $andExpr->add($qb->expr()->like($searchField, "?$i"));
                $qb->setParameter($i, "%" . $this->requestParams["columns"]["{$i}"]["search"]["value"] . "%");
            }
        }

        if ($andExpr->count() > 0) {
            $qb->andWhere($andExpr);
        }

        return $this;
    }

    /**
     * Set where callback functions.
     *
     * @param QueryBuilder $qb A QueryBuilder instance
     *
     * @return $this
     */
    public function setWhereCallbacks(QueryBuilder $qb)
    {
        if (!empty($this->callbacks["WhereBuilder"])) {
            foreach ($this->callbacks["WhereBuilder"] as $callback) {
                $callback($qb);
            }
        }

        return $this;
    }

    /**
     * Set orderBy.
     *
     * @return $this
     */
    public function setOrderBy()
    {
        $this->qb->addOrderBy(
            $this->allColumns[$this->requestParams["order"]["0"]["column"]],
            $this->requestParams["order"]["0"]["dir"]
        );

        return $this;
    }

    /**
     * Set the scope of the resultset (Paging).
     *
     * @return $this
     */
    public function setLimit()
    {
        if (isset($this->requestParams["start"]) && $this->requestParams["length"] != "-1") {
            $this->qb->setFirstResult($this->requestParams["start"])->setMaxResults($this->requestParams["length"]);
        }

        return $this;
    }

    /**
     * Constructs a Query instance.
     *
     * @return Query
     */
    public function execute()
    {
        $query = $this->qb->getQuery();
        $query->setHydrationMode(Query::HYDRATE_ARRAY);

        return $query;
    }
}