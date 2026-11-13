import importlib.util
from pathlib import Path
import unittest

spec=importlib.util.spec_from_file_location('wiring',Path(__file__).resolve().parents[1]/'ha-integration/tools/heating_wiring.py')
source=importlib.util.module_from_spec(spec);spec.loader.exec_module(source)

class WiringTests(unittest.TestCase):
    def test_changes_only_references_not_schedule_setpoint_or_device(self):
        old={'triggers':[{'entity_id':['sensor.room_next']},{'at':'18:00'}],
             'variables':{'x':"{{ state_attr('sensor.room_next', 'minutes_until_start') }}",'setpoint':21},
             'actions':[{'action':'switch.turn_off','target':{'entity_id':'switch.thermostat'}}]}
        new=source.rewire_heating(old,{'sensor.room_next':'sensor.heating_room_next'})
        self.assertEqual(new['actions'],old['actions']);self.assertEqual(new['triggers'][1],old['triggers'][1])
        self.assertEqual(old['triggers'][0]['entity_id'],['sensor.room_next'])
        self.assertEqual(new['variables']['setpoint'],21)
        self.assertIn('sensor.heating_room_next',new['variables']['x'])

    def test_refuses_already_migrated_or_ambiguous_config(self):
        for config,mapping in [({'id':'sensor.heating_room'},{'sensor.room':'sensor.heating_room'}),
                               ({'id':'sensor.room'},{'sensor.room':'sensor.new','sensor.other':'sensor.new'}),
                               ({'id':'sensor.unrelated'},{'sensor.room':'sensor.new'})]:
            with self.assertRaises(ValueError):source.rewire_heating(config,mapping)

    def test_does_not_replace_prefix_of_another_entity(self):
        new=source.rewire_heating({'exact':'sensor.room','other':'sensor.room_extra'}, {'sensor.room':'sensor.heating_room'})
        self.assertEqual(new['other'],'sensor.room_extra')

if __name__=='__main__':unittest.main()
