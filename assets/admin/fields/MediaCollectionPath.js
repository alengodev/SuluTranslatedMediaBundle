// @flow
import React from 'react';
import {action, comparer, observable, reaction} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Icon, Loader} from 'sulu-admin-bundle/components';
import FolderList from 'sulu-admin-bundle/components/FolderList';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {translate} from 'sulu-admin-bundle/utils';
import type {FieldTypeProps} from 'sulu-admin-bundle/types';

const COLLECTIONS_RESOURCE_KEY = 'collections';
const MEDIA_OVERVIEW_VIEW = 'sulu_media.overview';
type PathItem = {id: ?(number | string), title: string};

type Collection = {
    id: number | string,
    objectCount: number,
    path: Array<PathItem>,
    title: string,
};

const breadcrumbStyle = {display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '6px', margin: '20px 0 0', padding: 0};
const breadcrumbItemStyle = {display: 'flex', alignItems: 'center', gap: '6px', listStyle: 'none'};
const arrowStyle = {fontSize: '10px', color: '#999'};

/**
 * Read-only field showing the collection of the current media item as a folder tile
 * (like the media overview) with its clickable path below. Tile and path segments
 * open the media overview of the respective collection.
 * Value is the media's `collection` id (provided by the media API).
 */
@observer
class MediaCollectionPath extends React.Component<FieldTypeProps<?(number | string)>> {
    // Replaced as a whole, never mutated: no deep observable conversion needed
    @observable.ref collection: ?Collection = undefined;
    @observable loading: boolean = true;
    disposer: ?() => void;
    // Increments per request; responses of superseded requests are dropped
    requestId: number = 0;

    componentDidMount() {
        this.disposer = reaction(
            () => [this.props.value, this.locale],
            ([collectionId, locale]) => this.load(collectionId, locale),
            // Structural compare: re-rendered props (e.g. after saving) with the same
            // collection and locale must not trigger another request
            {equals: comparer.structural, fireImmediately: true}
        );
    }

    componentWillUnmount() {
        this.requestId++;
        if (this.disposer) {
            this.disposer();
        }
    }

    get locale(): ?string {
        const {formInspector} = this.props;

        return formInspector.locale ? formInspector.locale.get() : undefined;
    }

    @action load(collectionId: ?(number | string), locale: ?string) {
        const requestId = ++this.requestId;

        if (!collectionId) {
            this.collection = undefined;
            this.loading = false;

            return;
        }

        this.loading = true;
        ResourceRequester.get(COLLECTIONS_RESOURCE_KEY, {id: collectionId, locale, breadcrumb: true})
            .then(action((data) => {
                if (requestId !== this.requestId) {
                    return;
                }

                const breadcrumb = (data._embedded && data._embedded.breadcrumb) || [];
                this.collection = {
                    id: data.id,
                    objectCount: data.objectCount || 0,
                    path: [
                        {id: undefined, title: translate('sulu_media.all_media')},
                        ...breadcrumb.map(({id, title}) => ({id, title})),
                        {id: data.id, title: data.title},
                    ],
                    title: data.title,
                };
                this.loading = false;
            }))
            .catch(action(() => {
                if (requestId !== this.requestId) {
                    return;
                }

                this.collection = undefined;
                this.loading = false;
            }));
    }

    handleFolderClick = (collectionId: ?(number | string)) => {
        const {router} = this.props;
        if (!router) {
            return;
        }

        router.navigate(MEDIA_OVERVIEW_VIEW, {id: collectionId || undefined, locale: this.locale});
    };

    render() {
        // Keep showing the previous collection while e.g. a locale switch reloads it
        if (this.loading && !this.collection) {
            return <Loader size={20} />;
        }

        const {collection} = this;
        if (!collection) {
            return null;
        }

        const info = `${collection.objectCount} ${
            translate(collection.objectCount === 1 ? 'sulu_admin.object' : 'sulu_admin.objects')
        }`;

        return (
            <div>
                <FolderList onFolderClick={this.handleFolderClick}>
                    <FolderList.Folder
                        hasPermissions={false}
                        id={collection.id}
                        info={info}
                        title={collection.title}
                    />
                </FolderList>
                <ul style={breadcrumbStyle}>
                    {collection.path.map((item, index) => (
                        <li key={item.id || 'root'} style={breadcrumbItemStyle}>
                            {index > 0 && <Icon name="su-angle-right" style={arrowStyle} />}
                            <Button onClick={this.handleFolderClick} skin="link" value={item.id}>
                                {item.title}
                            </Button>
                        </li>
                    ))}
                </ul>
            </div>
        );
    }
}

export default MediaCollectionPath;
