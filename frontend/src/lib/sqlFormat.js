/**
 * Format SQL the way Symfony WebProfilerBundle's "formatted query" view
 * renders it — keywords on their own line, parameters substituted in place
 * of `?` placeholders, semicolon terminator. Inspired by the profiler
 * template at vendor/symfony/web-profiler-bundle/Resources/views/Profiler/
 * db.html.twig, but kept small and self-contained (no Twig dependency).
 *
 * Example:
 *   in:  "SELECT t0.id, t0.email FROM user t0 WHERE t0.id = ?",
 *        params: [9450162]
 *   out: "SELECT
 *           t0.id,
 *           t0.email
 *         FROM
 *           user t0
 *         WHERE
 *           t0.id = 9450162;"
 *
 * The output keeps it on one logical line per clause so a token-level SQL
 * highlighter (e.g. highlightSql) can still wrap keywords in <span>s —
 * just with newlines in the text content.
 */

/**
 * Substitute positional `?` placeholders with literal values from params.
 * Strings are single-quoted with proper doubling of embedded quotes;
 * numbers and booleans render as-is; null/undefined render as SQL NULL.
 */
export function substituteParams(sql, params) {
  if (!Array.isArray(params) || params.length === 0) return sql
  let i = 0
  return sql.replace(/\?/g, () => {
    const v = params[i++]
    if (v === null || v === undefined) return 'NULL'
    if (typeof v === 'number' && Number.isFinite(v)) return String(v)
    if (typeof v === 'boolean') return v ? 'TRUE' : 'FALSE'
    // string-like
    return `'${String(v).replace(/'/g, "''")}'`
  })
}

/**
 * Strip Doctrine ORM's `_N` column-alias suffixes. Doctrine generates
 * `SELECT u0_.id AS id_0, u1_.id AS id_1, ...` when the SELECT joins
 * tables that share column names (id, created_at, etc.). The suffixes
 * are needed for result-set hydration but are pure noise for humans.
 *
 * Patterns handled:
 *   `AS some_name_0`           → `AS some_name`
 *   `AS some_name_42`          → `AS some_name`
 *   `some_name_0`              → `some_name`  (in column lists without AS)
 *   `t0.id AS id_0, u1_.id AS id_1` → `t0.id AS id, u1_.id AS id`
 *
 * Edge cases preserved:
 *   - identifiers that LEGITIMATELY end with `_N` (no whitespace before)
 *     are not touched — we only strip when there's whitespace or comma
 *     immediately before the alias
 *   - numeric literals inside the SQL (rare but possible) aren't matched
 *     because we look for the `AS ` keyword OR a preceding comma
 */
export function stripDoctrineAliasSuffixes(sql) {
  if (!sql) return sql
  return sql
    // `AS <name>_<digit>` → `AS <name>` (with or without trailing punctuation)
    .replace(/\bAS\s+([A-Za-z_][A-Za-z0-9_]*?)_\d+\b/gi, 'AS $1')
    // Bare column aliases in lists: `, name_N` or ` name_N\n` → `, name` / ` name`
    // (only strip when followed by , or EOL — avoids mangling identifiers
    //  that happen to end in _N mid-SELECT)
    .replace(/(,|\s)([A-Za-z_][A-Za-z0-9_]*?)_\d+(?=\s*(?:,|$|\n))/g, '$1$2')
}

/**
 * Group the SELECT column list by table alias (u0_, u1_, w2_), one
 * block per table, separated by a blank line and a `-- Table (alias)`
 * comment. For a 50-column Doctrine SELECT this turns an unreadable
 * horizontal scroll into a clear "this column belongs to UserDomain,
 * this one to User, etc." view.
 *
 * Algorithm:
 *   1. Find the first top-level SELECT ... FROM region. Skip if the
 *      SQL uses SELECT * or has subqueries that complicate things.
 *   2. Extract the alias→table map from the FROM clause (e.g.
 *      `FROM user_domain u0_ LEFT JOIN "user" u1_ ON ...` → {u0_:user_domain, u1_:user})
 *   3. Split the SELECT list on top-level commas (respecting parens +
 *      quotes via the same splitTopLevel helper as param parsing).
 *   4. For each column, extract the leading alias (e.g. `u0_.id AS id_0`
 *      → alias `u0_`). Columns without an alias prefix (literals, function
 *      calls, `SELECT *`) go to a synthetic `__misc` group.
 *   5. Render each group with a header line, indented columns, blank
 *      line between groups.
 *
 * @returns {string} formatted SQL with grouped SELECT, or the original
 *   SQL unchanged when the heuristic can't safely parse it.
 */
export function groupSelectByAlias(sql) {
  if (!sql) return sql
  // Locate top-level SELECT ... FROM. We only handle the simplest case —
  // a SELECT that's not inside parentheses and is followed by FROM.
  // (GROUP BY, HAVING etc. don't introduce FROM between them.)
  const selectMatch = sql.match(/^SELECT\s+([\s\S]+?)\s+FROM\s+/i)
  if (!selectMatch) return sql
  const head = selectMatch[1]
  const tail = sql.slice(selectMatch[0].length)

  // Extract alias→table from the FROM/JOIN chain. Match patterns:
  //   FROM table alias
  //   FROM "table" alias
  //   JOIN table alias ON ...
  // The tail starts AFTER the FROM keyword, so we prepend "FROM " so the
  // first table (which doesn't have a leading JOIN) is matched too.
  const aliasToTable = {}
  const fromClause = tail.match(/^([\s\S]+?)(?:\s+WHERE\b|\s+GROUP\s+BY\b|\s+ORDER\s+BY\b|\s+HAVING\b|\s+LIMIT\b|$)/i)
  const fromText = (fromClause ? fromClause[1] : tail)
  const aliasRe = /(?:FROM|(?:LEFT|RIGHT|INNER|OUTER|CROSS|FULL)\s+JOIN|JOIN)\s+(?:"([^"]+)"|`([^`]+)`|(\w+))\s+(?:AS\s+)?([A-Za-z_][A-Za-z0-9_]*)/gi
  let m
  while ((m = aliasRe.exec('FROM ' + fromText)) !== null) {
    const table = m[1] || m[2] || m[3]
    const alias = m[4]
    if (table && alias) aliasToTable[alias] = table
  }

  // Split SELECT column list on top-level commas (handles parens + quotes).
  const columns = splitSelectList(head).map(c => c.trim()).filter(Boolean)
  if (columns.length < 4) return sql   // not worth grouping for tiny lists

  // Group by leading alias (e.g. `u0_.id` → u0_).
  const groups = new Map() // alias → array of column strings, in original order
  for (const col of columns) {
    const colMatch = col.match(/^\s*(?:"([^"]+)"|`([^`]+)`|(\w+))\./)
    const alias = colMatch ? (colMatch[1] || colMatch[2] || colMatch[3]) : '__misc'
    if (!groups.has(alias)) groups.set(alias, [])
    groups.get(alias).push(col)
  }
  // Preserve insertion order (first appearance = display order).
  const orderedAliases = [...groups.keys()]

  // Render each group: header comment + indented columns. Build as a
  // flat array of lines, then indent every non-blank line with two
  // spaces and join — the blank line between groups stays blank (no
  // trailing indent that would look like a stray space).
  const rendered = []
  for (let i = 0; i < orderedAliases.length; i++) {
    const alias = orderedAliases[i]
    const cols = groups.get(alias)
    const table = aliasToTable[alias]
    const header = table
      ? `-- ${table} (${alias})`
      : alias === '__misc'
        ? '-- literals / expressions'
        : `-- ${alias}`
    rendered.push(header)
    for (const c of cols) rendered.push(c)
    if (i < orderedAliases.length - 1) rendered.push('')  // blank line between groups
  }
  // One indent level (two spaces) on every non-blank line.
  const body = rendered.map(l => l === '' ? '' : '  ' + l).join('\n')

  return 'SELECT\n' + body + '\nFROM ' + tail
}

/**
 * Split a comma-separated list on TOP-LEVEL commas only — commas
 * inside parentheses, function calls, or quoted strings are NOT split.
 * Same algorithm as the SQL reconstructor's splitTopLevel but inlined
 * here so sqlFormat.js is self-contained.
 */
export function splitSelectList(s) {
  const parts = []
  let cur = ''
  let depth = 0
  let quote = null
  for (let i = 0; i < s.length; i++) {
    const c = s[i]
    if (quote !== null) {
      if (c === '\\' && i + 1 < s.length) { cur += c + s[++i]; continue }
      if (c === quote) quote = null
      cur += c
      continue
    }
    if (c === '\'' || c === '"') { quote = c; cur += c; continue }
    if (c === '(' || c === '[') { depth++; cur += c; continue }
    if (c === ')' || c === ']') { depth--; cur += c; continue }
    if (c === ',' && depth === 0) {
      parts.push(cur)
      cur = ''
      continue
    }
    cur += c
  }
  if (cur !== '') parts.push(cur)
  return parts
}

/**
 * Multi-line formatter — every top-level clause gets its own line, ON
 * sub-clauses of JOINs are indented further, AND/OR conditions break
 * onto their own line within WHERE.
 *
 * Whitespace inside parentheses is collapsed (Symfony does the same).
 * We deliberately don't reformat column lists — they're already
 * comma-separated and adding newlines per column would be overkill.
 *
 * @param {string} sql
 * @param {string[]|null} params
 * @param {object} [opts]
 * @param {boolean} [opts.cleanAliases=false] Doctrine ORM adds `_N`
 *   suffixes to every column alias when the SELECT joins tables with
 *   overlapping column names (e.g. `id` on both UserDomain and User).
 *   The suffixes are needed for hydration but are pure noise for humans
 *   reading the query. When true, `AS id_0` becomes `AS id` and the
 *   trailing `_N` is removed everywhere.
 * @param {boolean} [opts.groupByAlias=false] Break the SELECT column list
 *   into one block per table alias (u0_, u1_, w2_), separated by blank
 *   lines and a `-- Table (alias)` comment. For 50+ column Doctrine
 *   SELECTs this is the difference between "horizontal scroll for a
 *   mile" and "see at a glance which columns belong to which table".
 */
export function formatSql(sql, params, opts = {}) {
  if (!sql) return ''
  let out = substituteParams(sql, params || [])
  if (opts.cleanAliases) {
    out = stripDoctrineAliasSuffixes(out)
  }
  // Collapse runs of spaces/tabs to a single space first so the keyword
  // inserts land at predictable offsets.
  out = out.replace(/[ \t]+/g, ' ').trim()

    // Group the SELECT column list by table alias — replaces the giant
  // single-line column list with one block per table, separated by a
  // blank line and a "-- Table (alias)" comment. Done BEFORE the keyword
  // breaks so the resulting structure has SELECT on its own line followed
  // by indented groups.
  if (opts.groupByAlias) {
    out = groupSelectByAlias(out)
  }

  // Insert line breaks before major keywords. Match is case-insensitive and
  // uses word boundaries so `SELECT` inside an identifier (e.g.
  // `user_selections`) is not touched.
  const breaks = [
    // Top-level clauses: own line, no indent on the keyword itself.
    [/\bSELECT\b/gi,          '\nSELECT '],
    [/\bFROM\b/gi,            '\nFROM\n  '],
    [/\bWHERE\b/gi,           '\nWHERE\n  '],
    [/\bGROUP\s+BY\b/gi,      '\nGROUP BY\n  '],
    [/\bORDER\s+BY\b/gi,      '\nORDER BY\n  '],
    [/\bHAVING\b/gi,          '\nHAVING\n  '],
    [/\bLIMIT\b/gi,           '\nLIMIT '],
    [/\bOFFSET\b/gi,          '\nOFFSET '],
    [/\bUNION(\s+ALL)?\b/gi,  '\nUNION$1\n'],
    // JOIN + ON: indent JOIN to align with FROM, ON deeper under it.
    [/\b(LEFT\s+JOIN|RIGHT\s+JOIN|INNER\s+JOIN|OUTER\s+JOIN|CROSS\s+JOIN|FULL\s+JOIN|JOIN)\b/gi,
                                '\n  $1 '],
    [/\bON\b/gi,              '\n    ON '],
    // AND/OR inside WHERE — break to a new line at the same indent.
    [/\bAND\b/gi,             '\n  AND '],
    [/\bOR\b/gi,              '\n  OR '],
  ]
  for (const [re, repl] of breaks) {
    out = out.replace(re, repl)
  }

  // After FROM, JOIN, etc., break the column lists onto separate lines only
  // for the FROM clause (Symfony renders it that way). Keep column lists on
  // one line otherwise — long SELECT lists are still readable.
  // (This matches the Symfony profiler's "formatted query" output.)
  out = out.replace(/(FROM\s+)([\s\S]+?)(\s*(?:\n\s+(?:LEFT|RIGHT|INNER|OUTER|CROSS|FULL|JOIN|WHERE|GROUP|ORDER|HAVING|LIMIT|UNION)|;|$))/i,
    (_, head, body, tail) => {
      // Skip if body is tiny (single-line FROM).
      if (body.length < 60) return head + body + tail
      const indented = body.trim().split(',').map(s => '  ' + s.trim()).join(',\n')
      return head + '\n' + indented + tail
    })

  // Trim leading newline (the `SELECT` insert puts one before it).
  out = out.replace(/^\n/, '').trim()

  // Symfony always ends runnable queries with a semicolon. Add one if missing.
  if (!out.endsWith(';')) out += ';'

  return out
}