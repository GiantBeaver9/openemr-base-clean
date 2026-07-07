<?php

/**
 * Bootstrap — wires the Clinical Co-Pilot module into the OpenEMR host.
 *
 * Responsibilities (U1): register the module menu item (pre-visit copilot, next to the
 * schedule per USERS.md §2), expose the module's global config, and provide a Twig
 * environment. Additivity invariant (I9): when the module is disabled none of this runs
 * and the host behaves byte-for-byte identically.
 *
 * @package   OpenEMR\Modules\ClinicalCopilot
 * @link      https://www.open-emr.org
 * @author    Clinical Co-Pilot Build
 * @copyright Copyright (c) 2026 OpenEMR Foundation, Inc.
 * @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
 */

declare(strict_types=1);

namespace OpenEMR\Modules\ClinicalCopilot;

use OpenEMR\Common\Twig\TwigContainer;
use OpenEMR\Core\Kernel;
use OpenEMR\Core\OEGlobalsBag;
use OpenEMR\Menu\MenuEvent;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Twig\Environment;

class Bootstrap
{
    public const MODULE_INSTALLATION_PATH = "/interface/modules/custom_modules/";
    public const MODULE_NAME = "oe-module-clinical-copilot";

    /** ACL the copilot surfaces gate on (feature-level; patient scoping is structural, §4). */
    public const ACL_SECTION = "patients";
    public const ACL_SUBSECTION = "med";

    private string $moduleDirectoryName;
    private GlobalConfig $globalsConfig;
    private ?Environment $twig = null;
    private ?Kernel $kernel;

    public function __construct(
        private readonly EventDispatcherInterface $eventDispatcher,
        ?Kernel $kernel = null,
    ) {
        $this->kernel = $kernel;
        $this->moduleDirectoryName = basename(dirname(__DIR__));
        $this->globalsConfig = new GlobalConfig($GLOBALS);
    }

    public function subscribeToEvents(): void
    {
        $this->eventDispatcher->addListener(MenuEvent::MENU_UPDATE, $this->addCopilotMenuItem(...));
    }

    /**
     * Add the "Clinical Co-Pilot" entry to the top-level menu. acl_req mirrors the
     * feature gate the pages themselves re-check (defense in depth, §1.3).
     */
    public function addCopilotMenuItem(MenuEvent $event): MenuEvent
    {
        $menu = $event->getMenu();

        $item = new \stdClass();
        $item->requirement = 0;
        $item->target = 'cpl';
        $item->menu_id = 'copilot0';
        $item->label = xlt("Clinical Co-Pilot");
        $item->url = self::MODULE_INSTALLATION_PATH . self::MODULE_NAME . "/public/doc.php";
        $item->children = [];
        $item->acl_req = [self::ACL_SECTION, self::ACL_SUBSECTION];
        $item->global_req = [];

        $menu[] = $item;
        $event->setMenu($menu);

        return $event;
    }

    public function getGlobalConfig(): GlobalConfig
    {
        return $this->globalsConfig;
    }

    public function getTwig(): Environment
    {
        if ($this->twig === null) {
            $kernel = $this->kernel ?? OEGlobalsBag::getInstance()->getKernel();
            $this->twig = (new TwigContainer($this->getTemplatePath(), $kernel))->getTwig();
        }
        return $this->twig;
    }

    public function getTemplatePath(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'templates' . DIRECTORY_SEPARATOR;
    }
}
