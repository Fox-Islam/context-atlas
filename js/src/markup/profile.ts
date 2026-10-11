import { Document } from '../document/document.js';
import { ContextAtlasError } from '../errors.js';
import { buildLexical } from '../store/lexical.js';
import { readJson } from './jsonOutline.js';
import { readJsonl } from './jsonlOutline.js';
import type { Outline } from './outline.js';
import { readToml } from './tomlOutline.js';
import { readXml } from './xmlOutline.js';
import { MARKUP } from '../prose/profiles.js';
import { buildSubtrees } from './subtrees.js';
import { readYaml } from './yamlOutline.js';

/**
 * A YAML, JSON, JSON Lines, XML or TOML file as path subtrees linked by `$ref`, YAML aliases and
 * XML `id` references, with no Jev request
 * (spec/markup.md).
 *
 * @throws {ContextAtlasError} on another format, or a file its reader refuses
 */
export function ingestMarkupText(text: string, id: string, format: string, title: string, charsPerToken = 4): Document {
    const readers: Readonly<Record<string, (text: string) => Outline>> = { yaml: readYaml, json: readJson, jsonl: readJsonl, xml: readXml, toml: readToml };
    const reader = Object.hasOwn(readers, format) ? readers[format] : undefined;

    if (reader === undefined) {
        throw new ContextAtlasError(`Cannot read "${format}" as markup; use yaml, json, jsonl, xml or toml.`);
    }

    const outline = reader(text);
    const { units, links } = buildSubtrees(outline, title, charsPerToken);

    return new Document(id, MARKUP, units, links, buildLexical(units), { profile: MARKUP, score: null, by: 'format' }, { format });
}
