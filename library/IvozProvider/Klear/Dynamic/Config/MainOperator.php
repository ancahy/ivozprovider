<?php
namespace IvozProvider\Klear\Dynamic\Config;

class MainOperator extends Base
{

    protected $_title = '[Main Global Operator]';
    protected $_subTitle = '[master operator session]';
    protected $_year = '2016';
    
    protected $_sessionName = 'MainOperatorSession';
    protected $_userMapper = 'IvozProvider\Klear\Auth\MainOperators\Mapper';
    
    
    public function postInit()
    {
        if ($this->_user) {
            
            $this->_subTitle = "Operator: <strong>". $this->_user->getusername()."</strong>";
        
            if ($this->_user->brandId) {
                //TODO: translate
                $this->_subTitle .= '<br />Marca emulada: <strong>' . $this->_user->brandName .'</strong>';
            }
            
            if ($this->_user->companyId) {
                //TODO: translate
                $this->_subTitle .= '<br />Empresa emulada: <strong>' . $this->_user->companyName .'</strong>';
            }
        }
    }

    public function processSignature($signature)
    {
        return $this->_title;
    }
}