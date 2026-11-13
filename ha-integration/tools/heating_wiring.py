"""Pure, reversible entity-reference migration; never calls HA services."""
from copy import deepcopy
import json


def rewire_heating(config, mapping):
    """Change only exact entity-ID references, proving reversibility."""
    if not mapping or len(set(mapping.values())) != len(mapping):
        raise ValueError('Mapping must be nonempty and one-to-one')
    before = json.dumps(config, sort_keys=True)
    if any(new in before for new in mapping.values()):
        raise ValueError('Configuration already contains a destination ID')

    def walk(value, replacements):
        if isinstance(value, dict): return {k: walk(v, replacements) for k, v in value.items()}
        if isinstance(value, list): return [walk(v, replacements) for v in value]
        if isinstance(value, str):
            # IDs may appear as a scalar, in a list, or quoted inside Jinja.
            import re
            for old, new in replacements.items():
                value = re.sub(r'(?<![a-zA-Z0-9_])' + re.escape(old) + r'(?![a-zA-Z0-9_])', new, value)
            return value
        return deepcopy(value)

    result = walk(config, mapping)
    if result == config: raise ValueError('No matching heating references')
    if walk(result, {new: old for old, new in mapping.items()}) != config:
        raise ValueError('Migration is not exactly reversible')
    return result
