<?php

/*
 * This file is part of vaibhavpandeyvpz/doctrine-datatables package.
 *
 * (c) Vaibhav Pandey <contact@vaibhavpandey.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.md.
 */

namespace Doctrine\DataTables;

use Doctrine\DBAL\Query\QueryBuilder;

/**
 * Class Builder
 * @package Doctrine\DataTables
 */
class Builder
{
    /**
     * @var array
     */
    protected $columnAliases = array();

    /**
     * @var array
     */
    protected $columnSearchData = array();

    /**
     * @var array
     */
    protected $columnExpressions = array();

    /**
     * @var string
     */
    protected $indexColumn = '*';

    /**
     * @var QueryBuilder
     */
    protected $queryBuilder;

    /**
     * @var array
     */
    protected $requestParams;

    /**
     * @var boolean
     */
    protected $countDistinct = false;

    /**
     * @return array
     */
    public function getData()
    {
        $query = $this->getFilteredQuery();
        $columns = &$this->requestParams['columns'];
        // Order
        if (array_key_exists('order', $this->requestParams)) {
            $order = &$this->requestParams['order'];
            foreach ($order as $sort) {
                $column = &$columns[intval($sort['column'])];
                if (array_key_exists($column['name'], $this->columnAliases)) {
                    $column['data'] = $this->columnAliases[$column['name']];
                } else if (array_key_exists($column['data'], $this->columnAliases)) {
                    $column['data'] = $this->columnAliases[$column['data']];
                }
                $query->addOrderBy($column['data'], $sort['dir']);
            }
        }
        // Offset
        if (array_key_exists('start', $this->requestParams)) {
            $query->setFirstResult(intval($this->requestParams['start']));
        }
        // Limit
        if (array_key_exists('length', $this->requestParams)) {
            $length = intval($this->requestParams['length']);
            if ($length > 0) {
                $query->setMaxResults($length);
            }
        }
        // Fetch
        if ($query instanceof \Doctrine\ORM\QueryBuilder) {
        	return $query->getQuery()->getArrayResult();
        } else {
            return $query->execute()->fetchAll();
        }
    }

    /**
     * @return QueryBuilder
     */
    public function getFilteredQuery()
    {
        $query = clone $this->queryBuilder;
        $columns = &$this->requestParams['columns'];
        $c = count($columns);
        // Search
        if (array_key_exists('search', $this->requestParams)) {
            if ($value = trim($this->requestParams['search']['value'])) {
                $orX = $query->expr()->orX();
                for ($i = 0; $i < $c; $i++) {
                    $column = &$columns[$i];
                    if ($column['searchable'] == 'true') {
                        if (array_key_exists($column['name'], $this->columnAliases)) {
                            $column['data'] = $this->columnAliases[$column['name']];
                        } else if (array_key_exists($column['data'], $this->columnAliases)) {
                            $column['data'] = $this->columnAliases[$column['data']];
                        }
                        if (array_key_exists($column['data'], $this->columnExpressions) && is_callable($this->columnExpressions[$column['data']])) {
                            $orX->add(call_user_func($this->columnExpressions[$column['data']], $query, $value));
                        } else {
                            $x = $column['data'];
                            if (array_key_exists($column['data'], $this->columnSearchData)) {
                                $y = ":search_{$i}";
                                $v = $this->columnSearchData[$column['data']];
                                $searchValue = (is_callable($v)) ? call_user_func($v, $value) : $value ;
                                $query->setParameter("search_{$i}","%{$searchValue}%");
                            } else {
                                $y = ':search';
                            }
                            $orX->add($query->expr()->like($x, $y));
                        }
                    }
                }
                if ($orX->count() >= 1) {
                    $query->andWhere($orX)
                        ->setParameter('search', "%{$value}%");
                }
            }
        }
        // Filter
        for ($i = 0; $i < $c; $i++) {
            $column = &$columns[$i];
            $andX = $query->expr()->andX();
            if (($column['searchable'] == 'true') && ($value = trim($column['search']['value']))) {
                if (array_key_exists($column['name'], $this->columnAliases)) {
                    $column['data'] = $this->columnAliases[$column['name']];
                } else if (array_key_exists($column['data'], $this->columnAliases)) {
                    $column['data'] = $this->columnAliases[$column['data']];
                }
                if (array_key_exists($column['data'], $this->columnExpressions) && is_callable($this->columnExpressions[$column['data']])) {
                	$andX->add(call_user_func($this->columnExpressions[$column['data']], $query, $value));
                } else {
                    $x = $column['data'];
                    $y = ":filter_{$i}";
                    if (array_key_exists($column['data'], $this->columnSearchData)) {
                        $v = $this->columnSearchData[$column['data']];
                        $filterValue = (is_callable($v)) ? call_user_func($v, $value) : $value ;
                    } else {
                        $filterValue = $value;
                    }
                    $andX->add($query->expr()->eq($x, $y));
                    $query->setParameter("filter_{$i}", $filterValue);
                }
            }
            if ($andX->count() >= 1) {
                $query->andWhere($andX);
            }
        }
        // Done
        return $query;
    }

    /**
     * @return int
     */
    public function getRecordsFiltered()
    {
        if ($this->queryBuilder instanceof \Doctrine\ORM\QueryBuilder) {
            return $this->getFilteredQuery()
                ->resetDQLPart('select')
                ->select($this->getCountStr())
                ->getQuery()
                ->getSingleScalarResult();
        } else {
            return $this->getFilteredQuery()
                ->resetQueryPart('select')
                ->select($this->getCountStr())
                ->execute()
                ->fetchColumn(0);
        }
    }

    /**
     * @return int
     */
    public function getRecordsTotal()
    {
        $tmp = clone $this->queryBuilder;
        if ($tmp instanceof \Doctrine\ORM\QueryBuilder) {
            return $tmp->resetDQLPart('select')
                ->select($this->getCountStr())
                ->getQuery()
                ->getSingleScalarResult(0);
        } else {
            return $tmp->resetQueryPart('select')
                ->select($this->getCountStr())
                ->execute()
                ->fetchColumn(0);
        }
    }

    /**
     * @return array
     */
    public function getResponse()
    {
        return array(
            'data' => $this->getData(),
            'draw' => $this->requestParams['draw'],
            'recordsFiltered' => $this->getRecordsFiltered(),
            'recordsTotal' => $this->getRecordsTotal(),
        );
    }

    /**
     * @param string $indexColumn
     * @return static
     */
    public function withIndexColumn($indexColumn)
    {
        $this->indexColumn = $indexColumn;
        return $this;
    }

    /**
     * @param array $columnAliases
     * @return static
     */
    public function withColumnAliases($columnAliases)
    {
        $this->columnAliases = $columnAliases;
        return $this;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @return static
     */
    public function withQueryBuilder(QueryBuilder $queryBuilder)
    {
        $this->queryBuilder = $queryBuilder;
        return $this;
    }

    /**
     * @param QueryBuilder $queryBuilder
     * @return static
     */
    public function withORMQueryBuilder(\Doctrine\ORM\QueryBuilder $queryBuilder)
    {
    	$this->queryBuilder = $queryBuilder;
    	return $this;
    }

    /**
     * @param array $requestParams
     * @return static
     */
    public function withRequestParams($requestParams)
    {
        $this->requestParams = $requestParams;
        return $this;
    }

    /**
     * @param array $columnSearchData
     * @return static
     */
    public function withColumnSearchData($columnSearchData)
    {
    	$this->columnSearchData = $columnSearchData;
    	return $this;
    }

    /**
     * @param array $columnExpressions
     * @return static
     */
    public function withColumnExpressions($columnExpressions)
    {
    	$this->columnExpressions = $columnExpressions;
    	return $this;
    }

    /**
     * @return static
     */
    public function withCountDistinct()
    {
    	$this->countDistinct = true;
    	return $this;
    }

    private function getCountStr() {
    	if ($this->countDistinct) {
    		$countStr = sprintf("count(distinct(%s))", $this->indexColumn);
    	} else {
    		$countStr = sprintf("count(%s)", $this->indexColumn);
    	}
    	return $countStr;
    }
}
