"""Execute the live-2.x patch with a faithful polling-hook/timer adapter.

Not an HA runtime or interval-parser certification. No network or physical calls.
"""
import importlib.util
from datetime import datetime, timedelta, timezone
from pathlib import Path
import sys
from types import ModuleType, SimpleNamespace
import unittest

def module(name, **values):
    value=ModuleType(name);value.__dict__.update(values);sys.modules[name]=value;return value

class Base:
    @classmethod
    def __class_getitem__(cls, item): return cls
    def __init__(self,hass,*args,**kwargs): self.hass=hass;self.data=None;self.last_update_success=True;self.notifications=0
    def async_update_listeners(self): self.notifications+=1
    def async_set_updated_data(self,data): self.data=data;self.last_update_success=True;self.async_update_listeners()

now=datetime(2026,11,13,12,tzinfo=timezone.utc)
scheduled=[]
def track(hass,callback,at):
    record={'at':at,'callback':callback,'cancelled':False};scheduled.append(record)
    return lambda: record.update(cancelled=True)

module('homeassistant');module('homeassistant.helpers');module('homeassistant.util')
module('aiohttp',BasicAuth=object,ClientTimeout=object,ClientError=RuntimeError)
module('homeassistant.config_entries',ConfigEntry=object)
module('homeassistant.core',HomeAssistant=object,callback=lambda f:f)
module('homeassistant.exceptions',ConfigEntryAuthFailed=type('AuthFailed',(Exception,),{}))
module('homeassistant.helpers.aiohttp_client',async_get_clientsession=lambda _:None)
module('homeassistant.helpers.event',async_track_point_in_time=track)
module('homeassistant.helpers.update_coordinator',DataUpdateCoordinator=Base,UpdateFailed=type('UpdateFailed',(Exception,),{}))
module('homeassistant.util.dt',now=lambda:now,DEFAULT_TIME_ZONE=timezone.utc)
module('heating_patch').__path__=[]
module('heating_patch.booking_time',parse_booking_interval=lambda b,*_: (b['start'],b['end']))
constants={name:name for name in ('API_PATH_TODAY','CONF_APPLICATION_PASSWORD','CONF_GAP_MINUTES','CONF_POST_EVENT_MINUTES','CONF_PRE_EVENT_MINUTES','CONF_USERNAME','CONF_WEBSITE_URL','DOMAIN','EVENT_BOOKING_END','EVENT_BOOKING_START')}
constants.update(DEFAULT_GAP_MINUTES=30,DEFAULT_POST_EVENT_MINUTES=15,DEFAULT_PRE_EVENT_MINUTES=60)
module('heating_patch.const',**constants)
root=Path(__file__).resolve().parents[1]
for name,path in [('heating_policy','ha-integration/custom_components/mathlin_booking/heating_policy.py'),('coordinator','ha-integration/patches/2.0.0/coordinator.py')]:
    spec=importlib.util.spec_from_file_location('heating_patch.'+name,root/path)
    loaded=importlib.util.module_from_spec(spec);sys.modules[spec.name]=loaded;spec.loader.exec_module(loaded)
Coordinator=sys.modules['heating_patch.coordinator'].MathlinCoordinator

class CoordinatorTests(unittest.TestCase):
    def setUp(self):
        scheduled.clear();self.coordinator=Coordinator(SimpleNamespace(),SimpleNamespace(data={'CONF_WEBSITE_URL':'https://synthetic.invalid'},options={}))
        self.booking={'ref':'SYNTHETIC','space':'Main Scout Hall','start':now+timedelta(hours=2),'end':now+timedelta(hours=4),'heating_required':True}

    def poll(self,data):
        # This is the actual HA regular-refresh path, NOT its push-only setter.
        self.coordinator.data=data;self.coordinator.last_update_success=True;self.coordinator.async_update_listeners()

    def test_poll_reconciles_removed_booking(self):
        self.poll([self.booking]);self.assertEqual(len(scheduled),4)
        self.poll([]);self.assertTrue(all(x['cancelled'] for x in scheduled));self.assertEqual(self.coordinator._cancel_timers,[])

    def test_unchanged_boundary_and_setup_are_idempotent(self):
        self.poll([self.booking]);self.coordinator.async_update_listeners();self.assertEqual(len(scheduled),4)
        self.coordinator.schedule_booking_timers();self.assertEqual(sum(not x['cancelled'] for x in scheduled),4)

    def test_changed_interval_cancels_old_callbacks(self):
        self.poll([self.booking]);self.poll([{**self.booking,'end':now+timedelta(hours=5)}]);self.assertTrue(all(x['cancelled'] for x in scheduled[:4]));self.assertEqual(sum(not x['cancelled'] for x in scheduled),4)

    def test_failed_poll_preserves_last_valid_timers(self):
        self.poll([self.booking]);self.coordinator.last_update_success=False;self.coordinator.async_update_listeners();self.assertEqual(sum(not x['cancelled'] for x in scheduled),4)

    def test_explicit_opt_out_never_schedules(self):
        self.poll([{**self.booking,'heating_required':False}]);self.assertEqual(scheduled,[])

    def test_unload_cancels_every_timer(self):
        self.poll([self.booking]);self.coordinator.cancel_all_timers();self.assertTrue(all(x['cancelled'] for x in scheduled))

if __name__=='__main__': unittest.main()
