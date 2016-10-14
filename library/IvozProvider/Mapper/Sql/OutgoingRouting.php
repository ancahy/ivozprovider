<?php

/**
 * Application Model Mapper
 *
 * @package Mapper
 * @subpackage Sql
 * @author Luis Felipe Garcia
 * @copyright ZF model generator
 * @license http://framework.zend.com/license/new-bsd     New BSD License
 */

/**
 * Data Mapper implementation for IvozProvider\Model\OutgoingRouting
 *
 * @package Mapper
 * @subpackage Sql
 * @author Luis Felipe Garcia
 */
namespace IvozProvider\Mapper\Sql;
use IvozProvider\Gearmand\Jobs\Xmlrpc;

class OutgoingRouting extends Raw\OutgoingRouting
{

    protected function _save(\IvozProvider\Model\Raw\OutgoingRouting $model,
        $recursive = false, $useTransaction = true, $transactionTag = null, $forceInsert = false
    )
    {
        if ($model->getType() == 'group') {
            $model->setRoutingPatternId(null);
        } elseif ($model->getType() == 'pattern') {
            $model->setRoutingPatternGroupId(null);
        } elseif ($model->getType() == 'fax') {
            $model->setRoutingPatternId(null);
            $model->setRoutingPatternGroupId(null);
        } else {
            throw new \Exception("Incorrect Outgoing Routing Type");
        }

        $pk = parent::_save($model, true, $useTransaction, $transactionTag, $forceInsert);
        $this->updateLCRPerOutgoingRouting($model);
        return $pk;
    }

    public function updateLCRPerOutgoingRouting($outgoingRouting)
    {
        // If edit, delete everything and fresh-start (claramente mejorable)
        $lcrGatewaysMapper = new \IvozProvider\Mapper\Sql\LcrGateways();
        $this->_deleteRelated($lcrGatewaysMapper, $outgoingRouting->getPrimaryKey());

        $lcrRulesMapper = new \IvozProvider\Mapper\Sql\LcrRules();
        $this->_deleteRelated($lcrRulesMapper, $outgoingRouting->getPrimaryKey());

        $lcrRuleTargetsMapper = new \IvozProvider\Mapper\Sql\LcrRuleTargets();
        $this->_deleteRelated($lcrRuleTargetsMapper, $outgoingRouting->getPrimaryKey());

        $peeringContract = $outgoingRouting->getPeeringContract();
        if (empty($peeringContract)) {
            throw new \Exception("Peering Contract not found");
        }

        $peerServers = $peeringContract->getPeerServers();

        if (empty($peerServers)) {
            throw new \Exception("Peer Servers not found");
        }

        $lcrRules = array();
        // Create LcrRule for each RoutingPattern
        if ($outgoingRouting->getType() == 'group') {
            foreach ($outgoingRouting->getRoutingPatternGroup()->getRoutingPatternGroupsRelPatterns() as $relPattern) {
                $lcrRule = $this->_addLcrRulePerPattern($outgoingRouting, $relPattern->getRoutingPattern());
                array_push($lcrRules, $lcrRule);
            }
        } elseif ($outgoingRouting->getType() == 'pattern') {
                $lcrRule = $this->_addLcrRulePerPattern($outgoingRouting, $outgoingRouting->getRoutingPattern());
                array_push($lcrRules, $lcrRule);
        } elseif ($outgoingRouting->getType() == 'fax') {
                $lcrRule = $this->_addLcrRuleForFax($outgoingRouting);
                array_push($lcrRules, $lcrRule);
        } else {
            throw new \Exception("Incorrect Outgoing Routing Type");
        }

        $lcrGateways = array();
        // Create LcrGateway for each PeerServer in selected PeerContract
        foreach ($peerServers as $peerServer) {
            $lcrGateway = new \IvozProvider\Model\LcrGateways();
            $lcrGateway->setCompanyId($outgoingRouting->getCompanyId())
                       ->setGwName($peerServer->getName())
                       ->setIp($peerServer->getIp())
                       ->setHostname($peerServer->getHostname())
                       ->setPort($peerServer->getPort())
                       ->setParams($peerServer->getParams())
                       ->setUriScheme($peerServer->getUriScheme())
                       ->setTransport($peerServer->getTransport())
                       ->setStrip($peerServer->getStrip())
                       ->setPrefix($peerServer->getPrefix())
                       ->setTag($peerServer->getId())
                       ->setFlags($peerServer->getFlags())
                       ->setPeerServerId($peerServer->getId())
                       ->setOutgoingRoutingId($outgoingRouting->getPrimaryKey())
                       ->save();

            array_push($lcrGateways, $lcrGateway);
        }
        // Create n x m LcrRuleTargets (n LcrRules; m LcrGateways)
        foreach ($lcrRules as $lcrRule) {
            foreach ($lcrGateways as $lcrGateway) {
                $lcrRuleTarget = new \IvozProvider\Model\LcrRuleTargets();

                $lcrRuleTarget->setCompanyId($outgoingRouting->getCompanyId())
                              ->setRuleId($lcrRule->getId())
                              ->setGwId($lcrGateway->getId())
                              ->setPriority($outgoingRouting->getPriority())
                              ->setWeight($outgoingRouting->getWeight())
                              ->setOutgoingRoutingId($outgoingRouting->getPrimaryKey())
                              ->save();
            }
        }

        try {
            $this->_sendXmlRcp();
        } catch (\Exception $e) {
            $message = $e->getMessage()."<p>Target pattern may have been saved.</p>";
            throw new \Exception($message);
        }
    }

    public function delete(\IvozProvider\Model\Raw\ModelAbstract $model)
    {
        $response = parent::delete($model);
        try {
            $this->_sendXmlRcp();
        } catch (\Exception $e) {
            $message = $e->getMessage()."<p>Outgoing routing may have been deleted.</p>";
            throw new \Exception($message);
        }
        return $response;
    }

    protected function _deleteRelated($mapper, $pk)
    {
        foreach ($mapper->findByField("outgoingRoutingId", $pk) as $item) {
            $item->delete();
        }
    }

    protected function _sendXmlRcp()
    {
        $proxyServers = array(
                'proxytrunks' => "lcr.reload",
        );
        $xmlrpcJob = new Xmlrpc();
        $xmlrpcJob->setProxyServers($proxyServers);
        $xmlrpcJob->send();
    }


    protected function _addLcrRulePerPattern($model, $pattern)
    {
        $lcrRule = new \IvozProvider\Model\LcrRules();
        $lcrRule->setCompanyId($model->getCompanyId())
                ->setTag($pattern->getName())
                ->setDescription($pattern->getDescription())
                ->setRoutingPatternId($pattern->getId())
                ->setOutgoingRoutingId($model->getPrimaryKey())
                ->setCondition($pattern->getRegExp())
                ->save();
        
        return $lcrRule;
    }

    protected function _addLcrRuleForFax($model)
    {
        $lcrRule = new \IvozProvider\Model\LcrRules();
        $lcrRule->setCompanyId($model->getCompanyId())
                ->setTag("fax")
                ->setDescription("Special route for fax")
                ->setOutgoingRoutingId($model->getPrimaryKey())
                ->setCondition("fax")
                ->save();

        return $lcrRule;
    }
}