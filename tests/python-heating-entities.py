"""Run actual 2.x entity code against synthetic coordinator/time adapters."""
import asyncio
from datetime import datetime, timezone
import importlib.util
from pathlib import Path
import sys
from types import ModuleType, SimpleNamespace
import unittest


def module(name, **values):
    result = ModuleType(name); result.__dict__.update(values); sys.modules[name] = result
    return result


class Entity:
    @classmethod
    def __class_getitem__(cls, item): return cls
    def __init__(self, coordinator): self.coordinator = coordinator


clock = datetime(2026, 11, 13, 12, tzinfo=timezone.utc)
module('homeassistant'); module('homeassistant.components'); module('homeassistant.helpers'); module('homeassistant.util')
module('homeassistant.components.sensor', SensorEntity=type('Sensor', (), {}))
module('homeassistant.components.binary_sensor', BinarySensorEntity=type('Binary', (), {}),
       BinarySensorDeviceClass=SimpleNamespace(OCCUPANCY='occupancy', RUNNING='running'))
module('homeassistant.config_entries', ConfigEntry=object)
module('homeassistant.core', HomeAssistant=object)
module('homeassistant.helpers.entity_platform', AddEntitiesCallback=object)
module('homeassistant.helpers.update_coordinator', CoordinatorEntity=Entity)
module('homeassistant.util.dt', now=lambda: clock, DEFAULT_TIME_ZONE=timezone.utc)
module('heating_entity_test').__path__ = []
module('heating_entity_test.coordinator', MathlinCoordinator=object)
root = Path(__file__).resolve().parents[1] / 'ha-integration/custom_components/mathlin_booking'
loaded = {}
for name in ('const', 'booking_time', 'heating_policy', 'entity', 'binary_sensor', 'sensor'):
    spec = importlib.util.spec_from_file_location('heating_entity_test.' + name, root / (name + '.py'))
    obj = importlib.util.module_from_spec(spec); sys.modules[spec.name] = obj; spec.loader.exec_module(obj)
    loaded[name] = obj
B = loaded['binary_sensor']; S = loaded['sensor']; heating = loaded['entity'].heating_entity


def booking(ref, space, start='11:00', end='13:00', heat=False, kitchen=False):
    return dict(ref=ref, space=space, start_time=start, end_time=end,
                booking_date='2026-11-13', heating_required=heat, scout_use=not heat,
                integration_scope='booking_occupancy', kitchen=kitchen, all_day=False)


class EntityTests(unittest.TestCase):
    def setUp(self):
        self.entry = SimpleNamespace(entry_id='synthetic-entry')
        self.coordinator = SimpleNamespace(data=[booking('SCOUT', 'Main Scout Hall', kitchen=True),
                                                booking('HIRE', 'Meeting Room', heat=True)])

    def pair(self, cls, *args):
        return (cls(self.coordinator, self.entry, *args),
                heating(cls(self.coordinator, self.entry, *args)))

    def test_unheated_scout_still_occupies_main_hall(self):
        generic, heat = self.pair(B.MathlinSpaceOccupancySensor, 'Main Scout Hall')
        self.assertTrue(generic.is_on); self.assertFalse(heat.is_on)
        self.assertEqual(generic.extra_state_attributes['active_booking'], 'SCOUT')

    def test_kitchen_controls_retain_unheated_scout(self):
        generic, heat = self.pair(B.MathlinKitchenSensor)
        self.assertTrue(generic.is_on); self.assertFalse(heat.is_on)
        self.assertEqual(generic.extra_state_attributes['booked_by'], 'SCOUT')

    def test_external_hire_remains_in_both_scopes(self):
        generic, heat = self.pair(B.MathlinSpaceOccupancySensor, 'Meeting Room')
        self.assertTrue(generic.is_on); self.assertTrue(heat.is_on)

    def test_summary_scopes_are_distinct(self):
        generic, heat = self.pair(S.MathlinSummarySensor)
        self.assertEqual(generic.native_value, 2); self.assertEqual(heat.native_value, 1)
        self.assertEqual([b['ref'] for b in heat.extra_state_attributes['bookings']], ['HIRE'])

    def test_next_unheated_scout_does_not_hide_later_hire_preheat(self):
        self.coordinator.data = [booking('SCOUT', 'Main Scout Hall', '14:00', '15:00'),
                                 booking('HIRE', 'Main Scout Hall', '16:00', '17:00', heat=True)]
        generic, heat = self.pair(S.MathlinSpaceNextBookingSensor, 'Main Scout Hall')
        self.assertEqual(generic.native_value, '14:00'); self.assertEqual(heat.native_value, '16:00')

    def test_opt_in_then_out_preserves_generic_and_identity(self):
        generic, heat = self.pair(B.MathlinSpaceOccupancySensor, 'Main Scout Hall')
        before = (generic._attr_unique_id, heat._attr_unique_id)
        self.coordinator.data[0]['heating_required'] = True
        self.assertTrue(generic.is_on); self.assertTrue(heat.is_on)
        self.coordinator.data[0]['heating_required'] = False
        self.assertTrue(generic.is_on); self.assertFalse(heat.is_on)
        self.assertEqual(before, (generic._attr_unique_id, heat._attr_unique_id))

    def test_parent_booking_matches_both_rooms_only_in_correct_scope(self):
        self.coordinator.data = [loaded['heating_policy'].normalise_heating_booking(booking('WHOLE', 'Whole Centre'))]
        for space in ('Main Scout Hall', 'Meeting Room'):
            generic, heat = self.pair(B.MathlinSpaceOccupancySensor, space)
            self.assertTrue(generic.is_on); self.assertFalse(heat.is_on)

    def test_end_boundary_is_exclusive(self):
        self.coordinator.data = [booking('ENDED', 'Main Scout Hall', '11:00', '12:00', heat=True)]
        for e in self.pair(B.MathlinGlobalOccupancySensor): self.assertFalse(e.is_on)

    def test_registration_keeps_original_nine_ids_and_adds_nine_disjoint_ids(self):
        hass = SimpleNamespace(data={'mathlin_booking': {'synthetic-entry': self.coordinator}})
        entities = []
        for platform in (B, S):
            asyncio.run(platform.async_setup_entry(hass, self.entry, lambda batch, _: entities.extend(batch)))
        ids = [e._attr_unique_id for e in entities]
        self.assertEqual(len(ids), 18); self.assertEqual(len(set(ids)), 18)
        self.assertEqual(sum(e._heating_only for e in entities), 9)
        self.assertIn('synthetic-entry_main_scout_hall_occupied', ids)
        self.assertIn('synthetic-entry_main_scout_hall_occupied_heating', ids)
        self.assertIn('synthetic-entry_summary', ids)
        self.assertIn('synthetic-entry_summary_heating', ids)


if __name__ == '__main__': unittest.main()
