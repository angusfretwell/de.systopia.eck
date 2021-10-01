<?php
/*-------------------------------------------------------+
| CiviCRM Entity Construction Kit                        |
| Copyright (C) 2021 SYSTOPIA                            |
| Author: J. Schuppe (schuppe@systopia.de)               |
+--------------------------------------------------------+
| This program is released as free software under the    |
| Affero GPL license. You can redistribute it and/or     |
| modify it under the terms of this license which you    |
| can read by viewing the included agpl.txt or online    |
| at www.gnu.org/licenses/agpl.html. Removal of this     |
| copyright header is strictly prohibited without        |
| written permission from the original author(s).        |
+--------------------------------------------------------*/

namespace Civi\Eck\API;

use CRM_Eck_ExtensionUtil as E;
use \Civi\API\Event\ResolveEvent;
use Civi\API\Provider\ProviderInterface as API_ProviderInterface;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

class Entity implements API_ProviderInterface, EventSubscriberInterface {

  protected static $_entityTypes;

  public static function getSubscribedEvents() {
    return [
      'civi.api.resolve' => 'onApiResolve',
    ];
  }
  /**
   * @param int $version
   *   API version.
   * @return array<string>
   */
  public function getEntityNames($version) {
    return static::getEntityTypes();
  }

  /**
   * @param int $version
   *   API version.
   * @param string $entity
   *   API entity.
   * @return array<string>
   */
  public function getActionNames($version, $entity) {
    $actions = [];
    if (in_array($entity, static::getEntityTypes())) {
      $actions[] = 'get';
    }
    return $actions;
  }

  public static function getEntityTypes() {
    if (!isset(static::$_entityTypes)) {
      static::$_entityTypes = array_map(
        function($name) {
          return 'Eck' . $name;
        },
        array_column(
          civicrm_api3('EckEntityType', 'get', [], ['limit' => 0])['values'],
          'name'
        )
      );
    }
    return static::$_entityTypes;
  }

  public function onApiResolve(ResolveEvent $event) {
    if (in_array($event->getApiRequest()['entity'], static::getEntityTypes())) {
      $event->setApiProvider($this);
      $apiRequest = $event->getApiRequest();

      // TODO: Copied this from Civi\FormProcessor\API\FormProcessor - is this needed?
      if (strtolower($apiRequest['action']) == 'getfields' || strtolower($apiRequest['action']) == 'getoptions') {
        $event->stopPropagation();
      }
    }
  }

  /**
   * @param array $apiRequest
   *   The full description of the API request.
   * @return array
   *   structured response data (per civicrm_api3_create_success)
   * @see civicrm_api3_create_success
   * @throws \Exception
   */
  public function invoke($apiRequest) {
    switch (strtolower($apiRequest['action'])) {
      case 'get':
        return $this->invokeGet($apiRequest);
        break;
    }
  }

  public function invokeGet($apiRequest) {
    $bao_name = 'CRM_Eck_BAO_Entity';
    $entity = $apiRequest['entity'];
    $params = $apiRequest['params'];
    /**
     * Copied and adapted from _civicrm_api3_basic_get().
     * We need to always pass the ECK entity type into the DAO constructor and
     * static methods where objects are being instantiated.
     * Therefore, we need our own Api3SelectQuery class and override some static
     * methods in CRM_Eck_DAO_Entity.
     * @see \Civi\Eck\API\Api3SelectQuery
     * @see \CRM_Eck_DAO_Entity::getSelectWhereClause()
     */
    $entity = $entity ?: CRM_Core_DAO_AllCoreTables::getBriefName($bao_name);
    $options = _civicrm_api3_get_options_from_params($params);

    // Skip query if table doesn't exist yet due to pending upgrade
    if (!$bao_name::tableHasBeenAdded()) {
      \Civi::log()->warning("Could not read from {$entity} before table has been added. Upgrade required.", ['civi.tag' => 'upgrade_needed']);
      $result = [];
    }
    else {
      $query = new Api3SelectQuery($entity, $params['check_permissions'] ?? FALSE);
      $query->where = $params;
      if ($options['is_count']) {
        $query->select = ['count_rows'];
      }
      else {
        $query->select = array_keys(array_filter($options['return']));
        $query->orderBy = $options['sort'];
        $query->isFillUniqueFields = $uniqueFields;
      }
      $query->limit = $options['limit'];
      $query->offset = $options['offset'];
      $query->merge($sql);
      $result = $query->run();
    }

    if ($returnAsSuccess) {
      return civicrm_api3_create_success($result, $params, $entity, 'get');
    }
    return $result;
  }

}