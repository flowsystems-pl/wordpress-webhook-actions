export const escapeKey = (key) => key.replace(/\./g, '\\.');
export const unescapeKey = (key) => key.replace(/\\\./g, '.');
export const splitPathRaw = (path) => path.split(/(?<!\\)\./);
export const splitPath = (path) => splitPathRaw(path).map(unescapeKey);

export const joinPath = (prefix, key) => {
  const escaped = escapeKey(key);
  return prefix ? `${prefix}.${escaped}` : escaped;
};

export const flattenObject = (obj, prefix = '', depth = 0, maxDepth = 4) => {
  const result = [];
  if (!obj || typeof obj !== 'object' || depth > maxDepth) return result;

  const entries = Array.isArray(obj)
    ? obj.map((v, i) => [String(i), v])
    : Object.entries(obj);

  for (const [key, value] of entries) {
    const path = joinPath(prefix, key);
    const isArray = Array.isArray(value);
    const isObject = value !== null && typeof value === 'object';
    if (isObject) {
      result.push({ path, value, type: isArray ? 'array' : 'object', isExpandable: true, depth, childCount: isArray ? value.length : Object.keys(value).length });
      result.push(...flattenObject(value, path, depth + 1, maxDepth));
    } else {
      result.push({ path, value, type: value === null ? 'null' : typeof value, isExpandable: false, depth });
    }
  }
  return result;
};

export const applyCast = (value, cast) => {
  if (!cast) return value;
  if (cast === 'number') return Number(value);
  if (cast === 'string') return String(value ?? '');
  if (cast === 'boolean') {
    if (typeof value === 'boolean') return value;
    const s = String(value ?? '').toLowerCase().trim();
    if (['true', '1', 'on', 'yes'].includes(s)) return true;
    if (['false', '0', 'off', 'no', ''].includes(s)) return false;
    return s !== '' && s !== '0';
  }
  return value;
};

export const getValueByPath = (obj, path) => {
  const keys = splitPath(path);
  let current = obj;
  for (const key of keys) {
    if (current === null || current === undefined) return undefined;
    if (typeof current !== 'object') return undefined;
    current = Array.isArray(current) ? current[parseInt(key, 10)] : current[key];
  }
  return current;
};

export const setValueByPath = (obj, path, value, ref = null) => {
  const keys = splitPath(path);
  let current = obj;
  let currentRef = ref;

  for (let i = 0; i < keys.length - 1; i++) {
    const key = keys[i];
    const nextKey = keys[i + 1];

    if (current[key] === undefined || current[key] === null || typeof current[key] !== 'object') {
      let isNextArray = false;
      if (currentRef !== null && currentRef !== undefined && typeof currentRef === 'object') {
        const refVal = Array.isArray(currentRef) ? currentRef[parseInt(key, 10)] : currentRef[key];
        if (refVal !== undefined && refVal !== null) {
          isNextArray = Array.isArray(refVal);
        } else {
          isNextArray = /^\d+$/.test(nextKey);
        }
      } else {
        isNextArray = /^\d+$/.test(nextKey);
      }
      current[key] = isNextArray ? [] : {};
    }

    if (currentRef !== null && currentRef !== undefined && typeof currentRef === 'object') {
      currentRef = Array.isArray(currentRef) ? currentRef[parseInt(key, 10)] : currentRef[key];
    } else {
      currentRef = null;
    }

    current = current[key];
  }

  current[keys[keys.length - 1]] = value;
};

export const flattenForTransform = (obj, prefix = '', depth = 0, maxDepth = 10) => {
  const result = {};
  if (!obj || typeof obj !== 'object' || depth > maxDepth) return result;

  const entries = Array.isArray(obj)
    ? obj.map((v, i) => [String(i), v])
    : Object.entries(obj);

  for (const [key, value] of entries) {
    const path = joinPath(prefix, key);
    if (value !== null && typeof value === 'object') {
      Object.assign(result, flattenForTransform(value, path, depth + 1, maxDepth));
    } else {
      result[path] = value;
    }
  }
  return result;
};

export const isPathExcluded = (path, excludedPaths) => {
  for (const excludedPath of excludedPaths) {
    if (path === excludedPath || path.startsWith(excludedPath + '.')) return true;
  }
  return false;
};

/**
 * Apply field mapping transform to a payload.
 * Pure function — mirrors MappingEditor's transformedPreview logic.
 *
 * @param {object|null} payload
 * @param {{ mappings: Array, excluded: Array, includeUnmapped: boolean }} config
 * @returns {object|null}
 */
export const applyMappingTransform = (payload, { mappings, excluded, includeUnmapped }) => {
  if (!payload) return null;

  const activeMappings = (mappings || []).filter((m) => m.source && m.target);
  const excludedList = excluded || [];

  if (activeMappings.length === 0 && excludedList.length === 0 && includeUnmapped !== false) {
    return payload;
  }

  const flatPayload = flattenForTransform(payload);
  const result = {};
  const mappedSourcePaths = activeMappings.map((m) => m.source);

  for (const map of activeMappings) {
    let value = getValueByPath(payload, map.source);
    if (value !== undefined) {
      if (map.cast) value = applyCast(value, map.cast);
      setValueByPath(result, map.target, value, payload);
    }
  }

  if (includeUnmapped !== false) {
    for (const [path, value] of Object.entries(flatPayload)) {
      if (mappedSourcePaths.includes(path)) continue;
      if (isPathExcluded(path, excludedList)) continue;
      setValueByPath(result, path, value, payload);
    }
  }

  return result;
};
