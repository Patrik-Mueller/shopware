<?php
/**
 * Shopware 5
 * Copyright (c) shopware AG
 *
 * According to our dual licensing model, this program can be used either
 * under the terms of the GNU Affero General Public License, version 3,
 * or under a proprietary license.
 *
 * The texts of the GNU Affero General Public License with an additional
 * permission and of our proprietary license can be found at and
 * in the LICENSE file you have received along with this program.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * "Shopware" is a registered trademark of shopware AG.
 * The licensing of the program under the AGPLv3 does not imply a
 * trademark license. Therefore any rights, title and interest in
 * our trademarks remain entirely with us.
 */

namespace Shopware\Bundle\BenchmarkBundle\Provider;

use Doctrine\DBAL\Connection;
use Shopware\Bundle\BenchmarkBundle\BenchmarkProviderInterface;
use Shopware\Bundle\StoreFrontBundle\Struct\ShopContextInterface;

class ShipmentsProvider implements BenchmarkProviderInterface
{
    /**
     * @var Connection
     */
    private $dbalConnection;

    /**
     * @var ShopContextInterface
     */
    private $shopContext;

    /**
     * @var array
     */
    private $shipmentIds = [];

    public function __construct(Connection $dbalConnection)
    {
        $this->dbalConnection = $dbalConnection;
    }

    public function getName()
    {
        return 'shipments';
    }

    /**
     * {@inheritdoc}
     */
    public function getBenchmarkData(ShopContextInterface $shopContext)
    {
        $this->shopContext = $shopContext;

        return [
            'list' => $this->getShipments(),
            'usages' => $this->getShipmentUsages(),
        ];
    }

    /**
     * @return array
     */
    private function getShipments()
    {
        $queryBuilder = $this->dbalConnection->createQueryBuilder();

        $shippingCosts = $queryBuilder->select('dispatch.name, MIN(costs.value) as minPrice, MAX(costs.value) as maxPrice')
            ->from('s_premium_dispatch', 'dispatch')
            ->where('dispatch.id IN (:dispatchIds)')
            ->innerJoin('dispatch', 's_premium_shippingcosts', 'costs', 'dispatch.id = costs.dispatchID')
            ->groupBy('dispatch.id')
            ->setParameter(':dispatchIds', $this->getShipmentIds(), Connection::PARAM_INT_ARRAY)
            ->execute()
            ->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_UNIQUE | \PDO::FETCH_ASSOC);

        return array_map(function ($shippingCost) {
            $shippingCost['minPrice'] = (float) $shippingCost['minPrice'];
            $shippingCost['maxPrice'] = (float) $shippingCost['maxPrice'];

            return $shippingCost;
        }, $shippingCosts);
    }

    /**
     * @return array
     */
    private function getShipmentUsages()
    {
        $queryBuilder = $this->dbalConnection->createQueryBuilder();

        return $queryBuilder->select('dispatches.name, COUNT(orders.id) as usages')
            ->from('s_order', 'orders')
            ->where('dispatches.id IN (:dispatchIds)')
            ->leftJoin('orders', 's_premium_dispatch', 'dispatches', 'dispatches.id = orders.dispatchID')
            ->groupBy('orders.dispatchID')
            ->setParameter(':dispatchIds', $this->getShipmentIds(), Connection::PARAM_INT_ARRAY)
            ->execute()
            ->fetchAll();
    }

    /**
     * @return array
     */
    private function getShipmentIds()
    {
        $shopId = $this->shopContext->getShop()->getId();
        if (array_key_exists($shopId, $this->shipmentIds)) {
            return $this->shipmentIds[$shopId];
        }

        $categoryIds = $this->getPossibleCategoryIds();

        $queryBuilder = $this->dbalConnection->createQueryBuilder();

        $dispatchIds = $queryBuilder->select('dispatch.id')
            ->from('s_premium_dispatch', 'dispatch')
            ->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);

        $dispatchIds = array_combine($dispatchIds, $dispatchIds);

        $forbiddenCategoriesBuilder = $this->dbalConnection->createQueryBuilder();

        $forbiddenCategoriesByDispatchId = $forbiddenCategoriesBuilder->select('dispatch.dispatchID, dispatch.categoryID')
            ->from('s_premium_dispatch_categories', 'dispatch')
            ->execute()
            ->fetchAll(\PDO::FETCH_GROUP | \PDO::FETCH_COLUMN);

        // Figure out all dispatches, that forbid ALL categories being available to a shop
        foreach ($forbiddenCategoriesByDispatchId as $dispatchId => $forbiddenCategories) {
            $availableCategoryIds = array_combine($categoryIds, $categoryIds);
            foreach ($forbiddenCategories as $forbiddenCategory) {
                if (array_key_exists($forbiddenCategory, $availableCategoryIds)) {
                    unset($availableCategoryIds[$forbiddenCategory]);
                }
            }

            if (!$availableCategoryIds) {
                unset($dispatchIds[$dispatchId]);
            }
        }

        $this->shipmentIds[$shopId] = $dispatchIds;

        return $this->shipmentIds[$shopId];
    }

    /**
     * @return array
     */
    private function getPossibleCategoryIds()
    {
        $categoryId = $this->shopContext->getShop()->getCategory()->getId();

        $queryBuilder = $this->dbalConnection->createQueryBuilder();

        return $queryBuilder->select('category.id')
            ->from('s_categories', 'category')
            ->where('category.path LIKE :categoryIdPath')
            ->orWhere('category.id = :categoryId')
            ->setParameter(':categoryId', $categoryId)
            ->setParameter(':categoryIdPath', '%|' . $categoryId . '|%')
            ->execute()
            ->fetchAll(\PDO::FETCH_COLUMN);
    }
}
