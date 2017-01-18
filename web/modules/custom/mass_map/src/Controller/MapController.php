<?php

namespace Drupal\mass_map\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\paragraphs\Entity\Paragraph;

/**
 * Class MapController.
 *
 * @package Drupal\mass_map\Controller
 */
class MapController extends ControllerBase {

  /**
   * Content.
   *
   * @return array
   *   Render array that calls returns a list of locations
   */
  public function content($latitude, $longitude, $id) {
    $markup = '';
    $node_storage = \Drupal::entityManager()->getStorage('node');
    $node = $node_storage->load($id);

    $ids = array();

    foreach ($node->field_map_locations as $location){
        $ids[] = $location->getValue()['target_id'];
    }

    $locations = $this->get_locations($ids);

    foreach ($locations as $location){
      $markup .= "<li>" . $location['address'] . "</li>";
    }

    $markup = "<ul>" . $markup . "</ul>";
    $markup = "<h2>Map Page</h2>" . $markup;

    return [
      '#markup' => $markup,
    ];
  }

  /**
   * Get location information from nodes.
   *
   * @param $nids
   *    A list of nodes containing locations.
   * @return array
   *    An array of location data and addresses keyed by the nid it belongs to.
   */
  public function get_locations($nids) {
    $node_storage = \Drupal::entityManager()->getStorage('node');
    $nodes = $node_storage->loadMultiple($nids);

    $locations = array();

    foreach ($nodes as $node) {
      $address = NULL;
      $location = NULL;

      // Extract location info from right rail layout.
      if ($node->getType() == 'action') {
        $locations[$node->nid->value] = $this->get_action_location($node);
      }
      if ($node->getType() == 'stacked_layout') {
        $locations[$node->nid->value] = $this->get_stacked_layout_location($node);
      }

    }

  return $locations;

  }

  /**
   * Get location information from Right Rail node.
   *
   * @param $node
   *  Right Rail node
   * @return array
   *  And array containing the address and location information.
   */
  private function get_action_location($node){
      $address = NULL;
      $location = NULL;

      // The map could be in one of a couple of fields.
      // Use map from the banner if it contains one.
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
          if (!empty($location)){
              break;
          }
      }
      if (empty($location)) {
          // Get point for map from the details field.
          foreach ($node->field_action_details as $detail_id) {
              $detail = Paragraph::load($detail_id->target_id);
              if ($detail->getType() == 'map') {
                  $location = $detail->field_map->getValue();
                  $location = reset($location);
                  break;
              }
          }
      }
      // The address could be in one of a couple of fields.
      // Use address from the header if it contains one.
      foreach ($node->field_action_header as $header_id) {
          $header = Paragraph::load($header_id->target_id);
          if ($group_address = $this->get_address_contact_group($header)) {
              $address = $group_address;
              break;
          }
      }
      if (empty($address)){
          // Next place to check for the address is the contact group field.
          foreach ($node->field_contact_group as $group_id) {
              $group = Paragraph::load($group_id->target_id);
              if ($group->getType() == 'contact_group') {
                  if ($group_address = $this->get_address_contact_group($group)) {
                      $address = $group_address;
                      break;
                  }
              }
          }
      }
      if (empty($address)){
          // Last we check the sidebar for an address.
          foreach ($node->field_action_sidebar as $sidebar_id) {
              $sidebar = Paragraph::load($sidebar_id->target_id);
              if ($sidebar->getType() == 'contact_group') {
                  if ($group_address = $this->get_address_contact_group($sidebar)) {
                      $address = $group_address;
                      break;
                  }
              }
          }
      }
      return array(
        'address' => $address,
        'location' => $location,
      );
  }

    /**
     * Get location information from Stacked Layout node.
     *
     * @param $node
     *  Stacked Layout node.
     * @return array
     *  And array containing the address and location information.
     */
    private function get_stacked_layout_location($node) {
        $address = NULL;
        $location = NULL;

        foreach ($node->field_action_header as $header_id) {
            $header = Paragraph::load($header_id->target_id);
            if ($group_address = $this->get_address_contact_group($header)) {
                $address = $group_address;
                break;
            }
        }
        foreach ($node->field_bands as $band_id) {
            $band = Paragraph::load($band_id->target_id);
            foreach ($band->field_main as $band_main_id) {
                $band_main = Paragraph::load($band_main_id->target_id);
                if ($band_main->getType() == 'map') {
                    $location = $band_main->field_map->getValue();
                    $location = reset($location);
                    break;
                }
                if ($band_main->getType() == 'contact_group' && !empty($address)) {
                    if ($group_address = $this->get_address_contact_group($band_main)) {
                        $address = $group_address;
                        break;
                    }
                }
            }
            if (empty($address) && $band->getType() == '2up_stacked_band') {
                foreach ($band->field_right_rail as $band_rail_id) {
                    $band_rail = Paragraph::load($band_rail_id->target_id);
                    if ($band_rail->getType() == 'contact_group' && !empty($address)) {
                        if ($group_address = $this->get_address_contact_group($band_rail)) {
                            $address = $group_address;
                            break;
                        }
                    }
                }
            }
        }
        return array(
            'address' => $address,
            'location' => $location,
        );
    }

    private function get_address_contact_group($contact_group){
        $address = NULL;
        foreach ($contact_group->field_contact_info as $contact_info_id) {
            $contact_info = Paragraph::load($contact_info_id->target_id);
            if ($contact_info->field_address) {
                $address = $contact_info->field_address->value;
                break;
            }
        }
        return $address;
    }
}
