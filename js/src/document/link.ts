import { phpString } from '../support/php.js';

export interface LinkObject {
    type: string;
    from: string;
    to: string;
}

/** A typed relation between two units of one document, such as a passage that uses a term another defines. */
export class Link {
    constructor(
        readonly type: string,
        readonly from: string,
        readonly to: string,
    ) {}

    static fromObject(value: Record<string, unknown>): Link {
        return new Link(phpString(value['type']), phpString(value['from']), phpString(value['to']));
    }

    toObject(): LinkObject {
        return { type: this.type, from: this.from, to: this.to };
    }

    toJSON(): LinkObject {
        return this.toObject();
    }
}
