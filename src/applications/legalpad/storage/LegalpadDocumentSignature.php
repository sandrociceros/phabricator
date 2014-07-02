<?php

final class LegalpadDocumentSignature
  extends LegalpadDAO
  implements PhabricatorPolicyInterface {

  const VERIFIED = 0;
  const UNVERIFIED = 1;

  protected $documentPHID;
  protected $documentVersion;
  protected $signerPHID;
  protected $signerName;
  protected $signerEmail;
  protected $signatureData = array();
  protected $verified;
  protected $isExemption = 0;
  protected $exemptionPHID;
  protected $secretKey;

  private $document = self::ATTACHABLE;

  public function getConfiguration() {
    return array(
      self::CONFIG_SERIALIZATION => array(
        'signatureData' => self::SERIALIZATION_JSON,
      ),
    ) + parent::getConfiguration();
  }

  public function save() {
    if (!$this->getSecretKey()) {
      $this->setSecretKey(Filesystem::readRandomCharacters(20));
    }
    return parent::save();
  }

  public function isVerified() {
    return ($this->getVerified() != self::UNVERIFIED);
  }

  public function getDocument() {
    return $this->assertAttached($this->document);
  }

  public function attachDocument(LegalpadDocument $document) {
    $this->document = $document;
    return $this;
  }


/* -(  PhabricatorPolicyInterface  )----------------------------------------- */


  public function getCapabilities() {
    return array(
      PhabricatorPolicyCapability::CAN_VIEW,
    );
  }

  public function getPolicy($capability) {
    switch ($capability) {
      case PhabricatorPolicyCapability::CAN_VIEW:
        return $this->getDocument()->getPolicy(
          PhabricatorPolicyCapability::CAN_EDIT);
    }
  }

  public function hasAutomaticCapability($capability, PhabricatorUser $viewer) {
    return ($viewer->getPHID() == $this->getSignerPHID());
  }

  public function describeAutomaticCapability($capability) {
    return null;
  }

}
