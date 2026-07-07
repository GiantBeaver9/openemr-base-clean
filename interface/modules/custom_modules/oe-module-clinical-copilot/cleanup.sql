--
-- Clinical Co-Pilot uninstall — drops ONLY module-owned state (additivity test 3, I9).
--
-- WARNING (T7 / OPEN-2): these tables are the append-only provenance ledger — dropping
-- them destroys the "what did the physician see" record. The Module Manager confirms and
-- offers export-before-drop; disposal is otherwise an administrator export-then-purge
-- operation, never an application row delete.
--
-- @package   OpenEMR\Modules\ClinicalCopilot
-- @license   https://github.com/openemr/openemr/blob/master/LICENSE GNU General Public License 3
--

#IfRow2D background_services name mod_copilot_warm function mod_copilot_warm_run
DELETE FROM `background_services` WHERE `name` = 'mod_copilot_warm';
#EndIf

DROP TABLE IF EXISTS `mod_copilot_trace`;
DROP TABLE IF EXISTS `mod_copilot_chat_turn`;
DROP TABLE IF EXISTS `mod_copilot_chat_session`;
DROP TABLE IF EXISTS `mod_copilot_cadence`;
DROP TABLE IF EXISTS `mod_copilot_doc`;
