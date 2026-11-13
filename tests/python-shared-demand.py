"""Synthetic guards only: no Home Assistant connection or physical services."""
import copy
from datetime import datetime
import importlib.util
from pathlib import Path
import unittest
from jinja2.nativetypes import NativeEnvironment

spec = importlib.util.spec_from_file_location('guards', Path(__file__).resolve().parents[1] / 'ha-integration/tools/shared_demand_guards.py')
guards = importlib.util.module_from_spec(spec)
spec.loader.exec_module(guards)


class DemandTests(unittest.TestCase):
    def setUp(self):
        self.clock = datetime.fromisoformat('2026-11-09T18:00:00+00:00')
        self.states = {}
        self.attrs = {}
        for suffix in ('main_scout_hall_occupied', 'meeting_room_occupied', 'kitchen_booked'):
            self.states['binary_sensor.' + guards.PREFIX + suffix] = 'off'
        for suffix in ('main_scout_hall_next_booking', 'meeting_room_next_booking'):
            self.states['sensor.' + guards.PREFIX + suffix] = 'None'
        self.states.update({'input_number.booking_setpoint': '21',
            'input_number.preheat_rate_per_degree': '10', 'input_number.preheat_max_minutes': '60',
            'sensor.scout_hut_average_air_temperature': '18'})
        self.env = NativeEnvironment()
        self.env.filters['bool'] = lambda v: v is True or str(v).lower() in ('true','on','1')
        self.env.globals.update(states=lambda name: self.states.get(name, 'unknown'),
            state_attr=lambda name, attr: self.attrs.get(name, {}).get(attr),
            is_number=lambda value: value is not None and str(value).replace('.','',1).replace('-','',1).isdigit(),
            now=lambda: self.clock, as_timestamp=lambda value: value.timestamp(),
            today_at=lambda value: datetime.combine(self.clock.date(), datetime.strptime(value, '%H:%M:%S').time(), self.clock.tzinfo))

    def demands(self):
        return {zone: bool(self.env.from_string(guards.booking_demand(zone)).render())
            for zone in ('main','meeting','kitchen','hallway')}

    def next(self, suffix, minutes, kitchen=False):
        entity = 'sensor.' + guards.PREFIX + suffix + '_next_booking'
        self.states[entity] = 'Synthetic booking'
        self.attrs[entity] = {'minutes_until_start': minutes, 'kitchen': kitchen}

    def test_idle(self):
        self.assertEqual(self.demands(), dict.fromkeys(('main','meeting','kitchen','hallway'), False))

    def test_zone_independence(self):
        self.states['binary_sensor.' + guards.PREFIX + 'meeting_room_occupied'] = 'on'
        self.assertEqual(self.demands(), {'main':False,'meeting':True,'kitchen':False,'hallway':True})

    def test_preheat_boundary_and_removal(self):
        self.next('main_scout_hall', 30, True)
        self.assertEqual(self.demands(), {'main':True,'meeting':False,'kitchen':True,'hallway':True})
        self.next('main_scout_hall', 31, True)
        self.assertFalse(any(self.demands().values()))
        self.next('main_scout_hall', 0, True)
        self.assertTrue(self.demands()['main'])
        self.states['sensor.' + guards.PREFIX + 'main_scout_hall_next_booking'] = 'None'
        self.assertFalse(any(self.demands().values()))

    def test_overlap_kitchen(self):
        self.next('main_scout_hall', 10)
        self.next('meeting_room', 15, True)
        self.assertTrue(all(self.demands().values()))

    def test_unknown_occupancy_veto_only(self):
        self.states['binary_sensor.' + guards.PREFIX + 'main_scout_hall_occupied'] = 'unavailable'
        self.assertEqual(self.demands(), {'main':True,'meeting':False,'kitchen':False,'hallway':True})

    def scout(self, time, present=False, enabled=True):
        self.clock = self.clock.replace(hour=int(time[:2]), minute=int(time[3:]))
        self.states.update({'input_select.scouts_day':'Monday', 'input_datetime.scouts_start_time':'18:30:00',
            'input_datetime.scouts_stop_time':'20:00:00', 'input_number.scouts_setpoint':'21'})
        return bool(self.env.from_string(guards.scout_demand()).render(
            mbs_scout_enabled=enabled, mbs_scout_presence=present))

    def test_scout_preheat_attendance_stop(self):
        self.assertFalse(self.scout('17:59'))
        self.assertTrue(self.scout('18:00'))
        self.assertTrue(self.scout('18:34'))
        self.assertFalse(self.scout('18:35'))
        self.assertTrue(self.scout('18:35', True))
        self.assertFalse(self.scout('20:00', True))
        self.assertFalse(self.scout('18:15', True, False))

    def fixture(self):
        off = {'action':'switch.turn_off', 'target':{'entity_id':list(guards.ROOMS)}}
        scout = {'id':guards.SCOUT_ID, 'alias':'Scheduled Thermostat Control (Preheat + UFH)',
            'variables':{'anyone_home':'{{ false }}'}, 'conditions':[{'value_template':'{{ true }}'}],
            'triggers':[], 'actions':[copy.deepcopy(off) for _ in range(9)]}
        booking = {'id':guards.BOOKING_ID,'alias':'Booking HVAC Control (Space-Aware Preheat + Auto Off)',
            'triggers':[], 'variables':{}, 'actions':[{'action':'switch.turn_off',
                'target':{'entity_id':[list(guards.ROOMS)[0]]}} for _ in range(6)]}
        return [scout, booking, {'id':'safety', 'actions':[off]}]

    def test_narrow_transformation_and_reentry_rejection(self):
        original = self.fixture()
        baseline = copy.deepcopy(original)
        changed = guards.prepare(original)
        self.assertEqual(original, baseline)
        self.assertEqual(changed[2], original[2])
        self.assertEqual(changed[0]['triggers'], original[0]['triggers'])
        self.assertEqual(changed[0]['conditions'], original[0]['conditions'])
        self.assertEqual(len(changed[1]['triggers']), 2)
        with self.assertRaises(ValueError): guards.prepare(changed)
        original[0]['actions'][0]['target']['entity_id'] = ['switch.unknown']
        with self.assertRaises(ValueError): guards.prepare(original)


if __name__ == '__main__':
    unittest.main()
