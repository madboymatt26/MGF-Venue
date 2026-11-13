"""Pure HA contract tests, no Home Assistant runtime or physical services."""
import importlib.util
from pathlib import Path
import unittest

path = Path(__file__).resolve().parents[1] / "ha-integration/custom_components/mathlin_booking/heating_policy.py"
spec = importlib.util.spec_from_file_location("heating_policy", path)
policy = importlib.util.module_from_spec(spec)
spec.loader.exec_module(policy)


class HeatingPolicyTests(unittest.TestCase):
    def test_parent_alias_is_private_and_non_mutating(self):
        original = {"space": "Whole Centre", "ref": "PARENT"}
        mapped = policy.normalise_heating_booking(original)
        self.assertEqual(original["space"], "Whole Centre")
        self.assertEqual(mapped["space"], "Whole Headquarters")
        self.assertEqual(mapped["venue_space"], "Whole Centre")
        for value in ({"space":"Meeting Room"}, {"space":"Whole Headquarters"}, None):
            self.assertIs(policy.normalise_heating_booking(value), value)

    def test_external_legacy(self):
        self.assertTrue(policy.heating_requested({"ref": "TEST"}))

    def test_scout_default_off(self):
        for value in (True, 1, "1", "true"):
            self.assertFalse(policy.heating_requested({"scout_use": value}))

    def test_explicit_scout_opt_in(self):
        self.assertTrue(policy.heating_requested({"scout_use": True, "heating_required": True}))

    def test_explicit_off_and_invalid_values(self):
        for value in (False, None, "true", "false", 1, 0, [], {}):
            self.assertFalse(policy.heating_requested({"heating_required": value}))

    def test_malformed_record(self):
        for value in (None, [], "true", 1):
            self.assertFalse(policy.heating_requested(value))


if __name__ == "__main__":
    unittest.main()
