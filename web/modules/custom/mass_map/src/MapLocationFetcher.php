<?php

namespace Drupal\mass_map;

use Drupal\paragraphs\Entity\Paragraph;
use Drupal\image\Entity\ImageStyle;

/**
 * Class MapLocationFetcher.
 *
 * @package Drupal\mass_map
 */
class MapLocationFetcher {

  /**
   * Get location information from nodes.
   *
   * @param array $nids
   *   A list of nodes containing locations.
   *
   * @return array
   *   An array of location data and addresses keyed by the nid it belongs to.
   */
  public function getLocations(array $nids) {
    $node_storage = \Drupal::entityManager()->getStorage('node');
    $nodes = $node_storage->loadMultiple($nids);

    $locations = [];

    $locations['form'] = [
      'action' => '#',
      'inputs' => [
        '0' => [
          'path' => '@molecules/field-submit.twig',
          'data' => [
            'fieldSubmit' => [
              'inputText'     => [
                'labelText'   => 'Filter by city, town or zipcode',
                'required'    => 'false',
                'id'          => 'filter-by-location',
                'name'        => 'filter-by-location',
                'placeholder' => '',
              ],
              'buttonSearch' => [
                'text' => 'Update',
              ],
            ],
          ],
        ],
      ],
    ];

    $locations['activeFilters'] = [
      'nearby' => '02155',
    ];

    // mapProp.
    $locations['googleMap']['map']['zoom'] = 16;
    $locations['googleMap']['map']['center'] = [
      'lat' => '42.4072107',
      'lng' => '-71.3824374',
    ];

    foreach ($nodes as $node) {
      $nid = $node->nid->value;

      // Extract location and address info from right rail layout.
      if ($node->getType() == 'action') {
        $locations['googleMap']['markers'][$nid] = $this->getActionLocation($node);
        $locations['imagePromos'][$nid] = $this->getActionContacts($node);
      }
      // Extract location and address info from stacked layout.
      if ($node->getType() == 'stacked_layout') {
        $locations['googleMap']['markers'][$nid] = $this->getStackedLayoutLocation($node);
        $locations['imagePromos'][$nid] = $this->getStackedLayoutContacts($node);
      }

      $locations['googleMap']['markers'][$nid]['infoWindow'] = $locations['imagePromos'][$nid]['infoWindow'];
      $locations['googleMap']['markers'][$nid]['infoWindow']['name'] = $node->getTitle();
      $locations['googleMap']['markers'][$nid]['infoWindow']['description'] = $node->field_lede->value;
      unset($locations['imagePromos'][$nid]['infoWindow']);

      // Get the node title and link.
      $locations['imagePromos'][$nid]['title'] = [
        'text' => $node->getTitle(),
        'href' => $node->toUrl()->toString(),
        'type' => '',
      ];

      // Get the description for the node.
      $locations['imagePromos'][$nid]['description'] = [
        'rteElements' => [
          '0' => [
            'path' => '@atoms/11-text/raw-html.twig',
            'data' => [
              'rawHtml' => [
                'content' => $node->field_lede->value,
              ],
            ],
          ],
        ],
      ];

      // Get the link for the node.
      $locations['imagePromos'][$nid]['link'] = [
        'text' => "Directions",
        'href' => 'https://www.google.com/maps/place/' . $locations['imagePromos'][$nid]['location']['text'],
        'type' => "external",
        'info' => '',
      ];

      // Get image.
      $locations['imagePromos'][$nid]['image'] = '';
      if ($node->hasField('field_photo') && $node->get('field_photo')->referencedEntities()) {
        $locations['googleMap']['markers'][$nid]['infoWindow']['image'] = ImageStyle::load('thumbnail_190_107')->buildUrl($node->get('field_photo')->referencedEntities()[0]->getFileUri());
        $locations['imagePromos'][$nid]['image'] = [
          'image' => ImageStyle::load('thumbnail_190_107')->buildUrl($node->get('field_photo')->referencedEntities()[0]->getFileUri()),
          'text'  => $node->getTitle(),
          'href'  => '',
        ];
      }
    }

    return $locations;
  }

  /**
   * Get location information from Right Rail node.
   *
   * @param object $node
   *   Right Rail node.
   *
   * @return array
   *   And array containing the location information.
   */
  private function getActionLocation($node) {
    $location = NULL;

    // The map could be in one of a couple of fields.
    // Use map from the banner if it contains one.
    if (!empty($node->field_action_banner)) {
      foreach ($node->field_action_banner as $banner_id) {
        $banner = Paragraph::load($banner_id->target_id);
        foreach ($banner->field_full_bleed_ref as $full_bleed_id) {
          $full_bleed = Paragraph::load($full_bleed_id->target_id);
          if ($full_bleed->getType() == 'map') {
            $location = $full_bleed->field_map->getValue();
            $location = reset($location);
            break;
          }
        }
        if (!empty($location)) {
          break;
        }
      }
    }
    // If it is not in the header get map point from the details field.
    if (empty($location) && !empty($node->field_action_details)) {
      foreach ($node->field_action_details as $detail_id) {
        $detail = Paragraph::load($detail_id->target_id);
        if ($detail->getType() == 'map') {
          $location = $detail->field_map->getValue();
          $location = reset($location);
          break;
        }
      }
    }
    return [
      'position' => [
        'lat' => $location['lat'],
        'lng' => $location['lon'],
      ],
      'label' => "",
    ];
  }

  /**
   * Get address information from Right Rail node.
   *
   * @param object $node
   *   Right Rail node.
   *
   * @return array
   *   And array containing the address information.
   */
  private function getActionContacts($node) {
    $contacts = [];
    $address = NULL;
    $email = NULL;
    $phone = NULL;

    // The address could be in one of a couple of fields.
    // Use address from the header if it contains one.
    if (!empty($node->field_action_header)) {
      foreach ($node->field_action_header as $header_id) {
        $header = Paragraph::load($header_id->target_id);
        $contacts = $this->getContactData($header);
      }
    }
    if (empty($address) && !empty($node->field_contact_group)) {
      // Next place to check for the address is the contact group field.
      foreach ($node->field_contact_group as $group_id) {
        $group = Paragraph::load($group_id->target_id);
        if ($group->getType() == 'contact_group') {
          $contacts = $this->getContactData($group);
        }
      }
    }
    if (empty($address) && !empty($node->field_action_sidebar)) {
      // Last we check the sidebar for an address.
      foreach ($node->field_action_sidebar as $sidebar_id) {
        $sidebar = Paragraph::load($sidebar_id->target_id);
        if ($sidebar->getType() == 'contact_group') {
          $contacts = $this->getContactData($sidebar);
        }
      }
    }

    return $this->formatContacts($contacts);
  }

  /**
   * Get location information from Stacked Layout node.
   *
   * @param object $node
   *   Stacked Layout node.
   *
   * @return array
   *   And array containing the location information.
   */
  private function getStackedLayoutLocation($node) {
    $location = NULL;

    if (!empty($node->field_bands)) {
      foreach ($node->field_bands as $band_id) {
        // Search the main bands field for location and address information.
        $band = Paragraph::load($band_id->target_id);
        if (!empty($band->field_main)) {
          foreach ($band->field_main as $band_main_id) {
            $band_main = Paragraph::load($band_main_id->target_id);
            if ($band_main->getType() == 'map') {
              $location = $band_main->field_map->getValue();
              $location = reset($location);
              break;
            }
          }
        }
      }
    }
    return [
      'position' => [
        'lat' => $location['lat'],
        'lng' => $location['lon'],
      ],
      'label' => "",
    ];
  }

  /**
   * Get address information from Stacked Layout node.
   *
   * @param object $node
   *   Stacked Layout node.
   *
   * @return array
   *   And array containing the address information.
   */
  private function getStackedLayoutContacts($node) {
    $contacts = [];
    $address = NULL;
    $email = NULL;
    $phone = NULL;

    // Get address from header if it has one.
    if (!empty($node->field_action_header)) {
      foreach ($node->field_action_header as $header_id) {
        $header = Paragraph::load($header_id->target_id);
        $contacts = $this->getContactData($header);
      }
    }
    if (!empty($node->field_bands)) {
      foreach ($node->field_bands as $band_id) {
        // Search the main bands field for location and address information.
        $band = Paragraph::load($band_id->target_id);
        if (!empty($band->field_main)) {
          foreach ($band->field_main as $band_main_id) {
            $band_main = Paragraph::load($band_main_id->target_id);
            if ($band_main->getType() == 'contact_group') {
              $contacts = $this->getContactData($band_main);
            }
          }
        }
        // Check the right rail of 2up bands for address info.
        if (empty($address) && $band->getType() == '2up_stacked_band') {
          if (!empty($band->field_right_rail)) {
            foreach ($band->field_right_rail as $band_rail_id) {
              $band_rail = Paragraph::load($band_rail_id->target_id);
              if ($band_rail->getType() == 'contact_group') {
                $contacts = $this->getContactData($band_rail);
              }
            }
          }
        }
      }
    }
    return $this->formatContacts($contacts);
  }

  /**
   * Get data out of a contact group if it contains one.
   *
   * @param object $contact_group
   *   The contact group paragraph object.
   * @param string $field
   *   The machine name for the field in the contact group paragraph.
   *
   * @return string
   *   The contact data if the group contains one.
   */
  private function getDataContactGroup($contact_group, $field) {
    $data = '';
    if (!empty($contact_group->field_contact_info)) {
      foreach ($contact_group->field_contact_info as $contact_info_id) {
        $contact_info = Paragraph::load($contact_info_id->target_id);
        // Check contact info paragraph for email.
        if ($contact_info->$field && !empty($contact_info->$field->value)) {
          $data = $contact_info->$field->value;
        }
      }
    }
    return $data;
  }

  /**
   * Get Contact data.
   *
   * @param object $region
   *   The region of the paragraph object.
   *
   * @return array
   *   And array containing contact data.
   */
  private function getContactData($region) {
    $fields = ['field_phone', 'field_email', 'field_address'];
    $contacts = [];

    foreach ($fields as $field) {
      $contacts[$field] = $this->getDataContactGroup($region, $field);
    }

    return $contacts;
  }

  /**
   * Format Contacts.
   *
   * @param array $contacts
   *   Contacts data.
   *
   * @return array
   *   And structured array containing location and infoWindow data.
   */
  private function formatContacts(array $contacts) {
    return [
      'location' => [
        'text' => isset($contacts['field_address']) ? $contacts['field_address'] : '',
        'map'  => 'true',
      ],
      'infoWindow' => [
        'name'     => '',
        'phone'    => isset($contacts['field_phone']) ? $contacts['field_phone'] : '',
        'fax'      => '',
        'email'    => isset($contacts['field_email']) ? $contacts['field_email'] : '',
        'address'  => isset($contacts['field_address']) ? $contacts['field_address'] : '',
      ],
    ];
  }

}
