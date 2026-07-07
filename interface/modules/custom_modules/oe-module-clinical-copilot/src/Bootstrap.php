<?php

/**
 * Clinical Co-Pilot Module Bootstrap Class
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Team
 * @copyright Copyright (c) 2026 OpenEMR Foundation
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Logging\SystemLogger;
use OpenEMR\Menu\MenuEvent;
use stdClass;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = '/interface/modules/custom_modules/oe-module-clinical-copilot';
    public const MODULE_NAME = 'oe-module-clinical-copilot';

    /**
     * Site administrators may grant/deny this section independently of the
     * 'patients'/'med' section this module's pages are gated on at runtime
     * (see build-notes.md). Best-effort registration of the section itself
     * happens in ModuleManagerListener::install() via AclExtended, since no
     * modern Symfony ACL-extension event exists for custom modules to hook
     * (verified against src/Events/ — only the legacy gacl API supports this).
     */
    public const ACL_SECTION_NAME = 'clinical_copilot';

    private readonly SystemLogger $logger;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
        $this->logger = new SystemLogger();
    }

    public function subscribeToEvents(): void
    {
        $this->registerMenuItems();
    }

    public function registerMenuItems(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addCustomMenuItem(...));
    }

    /**
     * Adds a "Clinical Co-Pilot" item under the Reports menu, linking to the
     * pre-visit synthesis document page (built in U8). Defensive: any
     * failure to locate the Reports menu or build the item is logged and
     * swallowed rather than breaking the host menu (I6/I7 style degradation
     * — this module must never take down core navigation).
     *
     * @param MenuEvent $event
     * @return MenuEvent
     */
    public function addCustomMenuItem(MenuEvent $event): MenuEvent
    {
        try {
            $menu = $event->getMenu();

            foreach ($menu as $menuItem) {
                $menuId = $menuItem->menu_id ?? '';
                $label = $menuItem->label ?? '';
                if ($menuId === 'repimg' || $label === 'Reports') {
                    $copilotMenuItem = new stdClass();
                    $copilotMenuItem->requirement = 0;
                    $copilotMenuItem->target = 'copilot0';
                    $copilotMenuItem->menu_id = 'copilot0';
                    $copilotMenuItem->label = xlt('Clinical Co-Pilot');
                    $copilotMenuItem->url = self::MODULE_INSTALLATION_PATH . '/public/doc.php';
                    $copilotMenuItem->children = [];
                    $copilotMenuItem->acl_req = ['patients', 'med'];
                    $copilotMenuItem->global_req = [];

                    $menuItem->children[] = $copilotMenuItem;
                    break;
                }
            }

            $event->setMenu($menu);
        } catch (\Throwable $e) {
            $this->logger->error('ClinicalCopilot: failed to register menu item', ['error' => $e->getMessage()]);
        }

        return $event;
    }
}
