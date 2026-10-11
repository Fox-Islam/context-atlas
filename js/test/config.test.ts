import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { describe, it } from 'node:test';
import { CONFIG_RULES } from '../src/config.js';
import { Config, ContextAtlasError } from '../src/index.js';

interface Property {
    type: string;
    default: unknown;
    minimum?: number;
    maximum?: number;
}

const schema = JSON.parse(readFileSync(new URL('../../../spec/config.schema.json', import.meta.url), 'utf8')) as {
    properties: Record<string, Property>;
};

describe('Config', () => {
    it('defaults to the schema defaults, under the schema keys', () => {
        const defaults = Object.fromEntries(Object.entries(schema.properties).map(([key, property]) => [key, property.default]));

        assert.deepEqual(new Config().toObject(), defaults);
    });

    it('checks each key against the schema type and bounds', () => {
        for (const [key, property] of Object.entries(schema.properties)) {
            const { key: _, default: __, ...rule } = CONFIG_RULES[key]!;

            assert.deepEqual(rule, Object.fromEntries(Object.entries(property).filter(([name]) => ['type', 'minimum', 'maximum'].includes(name))), key);
        }
    });

    it('reads snake_case keys', () => {
        const config = Config.fromObject({ max_tokens: 120, cross_check: false, seam_threshold: 1 });

        assert.equal(config.maxTokens, 120);
        assert.equal(config.crossCheck, false);
        assert.equal(config.seamThreshold, 1);
        assert.equal(config.minTokens, 200);
    });

    it('refuses an unknown key', () => {
        assert.throws(() => Config.fromObject({ max_token: 1 }), new ContextAtlasError('Unknown config key "max_token".'));
        assert.throws(() => Config.fromObject({ constructor: 1 }), /Unknown config key "constructor"/);
    });

    it('refuses a value of the wrong type or out of range', () => {
        assert.throws(() => Config.fromObject({ max_tokens: 1.5 }), /"max_tokens" must be an integer at least 1/);
        assert.throws(() => Config.fromObject({ max_tokens: '800' }), /"max_tokens" must be an integer/);
        assert.throws(() => Config.fromObject({ rank_threshold: 2 }), /"rank_threshold" must be a number between 0 and 1/);
        assert.throws(() => Config.fromObject({ cross_check: 1 }), /"cross_check" must be true or false/);
    });
});
