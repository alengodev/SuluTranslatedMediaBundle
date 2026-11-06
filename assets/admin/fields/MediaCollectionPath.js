// @flow
import React from 'react';
import {action, observable, reaction} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Icon, Loader} from 'sulu-admin-bundle/components';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import type {FieldTypeProps} from 'sulu-admin-bundle/types';

const COLLECTIONS_RESOURCE_KEY = 'collections';
const MEDIA_OVERVIEW_VIEW = 'sulu_media.overview';

type PathItem = {id: ?(number | string), title: string};

const listStyle = {display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '6px', margin: 0, padding: 0};
const itemStyle = {display: 'flex', alignItems: 'center', gap: '6px', listStyle: 'none'};
const arrowStyle = {fontSize: '10px', color: '#999'};

/**
 * Read-only field showing the collection path of the current media item.
 * Each path segment links to the media overview of that collection.
 * Value is the media's `collection` id (provided by the media API).
 */
@observer
class MediaCollectionPath extends React.Component<FieldTypeProps<?(number | string)>> {
    @observable path: ?Array<PathItem> = undefined;
    @observable loading: boolean = false;
    disposer: ?() => void;

    componentDidMount() {
        const {formInspector} = this.props;

        this.disposer = reaction(
            () => [this.props.value, formInspector.locale ? formInspector.locale.get() : undefined],
            ([collectionId, locale]) => this.load(collectionId, locale),
            {fireImmediately: true}
        );
    }

    componentWillUnmount() {
        if (this.disposer) {
            this.disposer();
        }
    }

    @action load(collectionId: ?(number | string), locale: ?string) {
        if (!collectionId) {
            this.path = [];

            return;
        }

        this.loading = true;
        ResourceRequester.get(COLLECTIONS_RESOURCE_KEY, {id: collectionId, locale, breadcrumb: true})
            .then(action((data) => {
                const breadcrumb = (data._embedded && data._embedded.breadcrumb) || [];
                this.path = [
                    ...breadcrumb.map(({id, title}) => ({id, title})),
                    {id: data.id, title: data.title},
                ];
                this.loading = false;
            }))
            .catch(action(() => {
                this.path = [];
                this.loading = false;
            }));
    }

    handleClick = (collectionId: ?(number | string)) => {
        const {formInspector, router} = this.props;
        if (!router) {
            return;
        }

        router.navigate(MEDIA_OVERVIEW_VIEW, {
            id: collectionId || undefined,
            locale: formInspector.locale ? formInspector.locale.get() : undefined,
        });
    };

    render() {
        if (this.loading || this.path === undefined) {
            return <Loader size={20} />;
        }

        const items: Array<PathItem> = [{id: undefined, title: translate('sulu_media.all_media')}, ...this.path];

        return (
            <ul style={listStyle}>
                {items.map((item, index) => (
                    <li key={item.id || 'root'} style={itemStyle}>
                        {index > 0 && <Icon name="su-angle-right" style={arrowStyle} />}
                        <Button onClick={this.handleClick} skin="link" value={item.id}>
                            {item.title}
                        </Button>
                    </li>
                ))}
            </ul>
        );
    }
}

export default MediaCollectionPath;
