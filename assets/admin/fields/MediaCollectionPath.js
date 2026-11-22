// @flow
import React from 'react';
import {action, comparer, observable, reaction} from 'mobx';
import {observer} from 'mobx-react';
import {Button, Icon, Loader} from 'sulu-admin-bundle/components';
import FolderList from 'sulu-admin-bundle/components/FolderList';
import {ResourceRequester} from 'sulu-admin-bundle/services';
import {transformBytesToReadableString, translate} from 'sulu-admin-bundle/utils';
import type {FieldTypeProps} from 'sulu-admin-bundle/types';

const COLLECTIONS_RESOURCE_KEY = 'collections';
const MEDIA_OVERVIEW_VIEW = 'sulu_media.overview';
// Parts of the media's storageOptions forming its storage path, in the order of
// Sulu's FlysystemStorage::getFilePath() (directory is only set for e.g. trashed files)
const STORAGE_PATH_KEYS = ['directory', 'segment', 'fileName'];

type PathItem = {id: ?(number | string), title: string};

type Collection = {
    id: number | string,
    objectCount: number,
    path: Array<PathItem>,
    title: string,
};

type Storage = {
    mimeType: ?string,
    path: string,
    size: ?number,
};

// Same spacing as the tiles of Sulu's FolderList (30px), whose list compensates its items' margins
const tilesStyle = {display: 'flex', flexWrap: 'wrap', alignItems: 'flex-start', gap: '30px'};
// Storage tile: mirrors Sulu's FolderList.Folder (folder.scss), but is not clickable and
// takes the remaining width of the row (at least a folder tile's width, else it wraps)
const storageTileStyle = {
    display: 'flex',
    flex: '1 1 240px',
    boxSizing: 'border-box',
    minWidth: '240px',
    height: '80px',
    padding: '10px',
    border: '1px solid #ccc',
    borderRadius: '3px',
    background: '#fff',
};
const storageIconStyle = {padding: '10px', fontSize: '26px'};
const storageDescriptionStyle = {
    display: 'flex',
    flexDirection: 'column',
    justifyContent: 'center',
    minWidth: 0,
    marginLeft: '10px',
};
const storageTitleStyle = {
    overflow: 'hidden',
    maxHeight: '35px',
    margin: '0 0 5px',
    fontSize: '12px',
    lineHeight: '18px',
    // Long paths wrap at regular break points (e.g. after "-") first, mid-word only if needed
    overflowWrap: 'anywhere',
};
const storageInfoStyle = {
    overflow: 'hidden',
    fontSize: '10px',
    color: '#a2a2a2',
    textOverflow: 'ellipsis',
    whiteSpace: 'nowrap',
};
const breadcrumbStyle = {display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: '6px', margin: '20px 0 0', padding: 0};
const breadcrumbItemStyle = {display: 'flex', alignItems: 'center', gap: '6px', listStyle: 'none'};
const arrowStyle = {fontSize: '10px', color: '#999'};

/**
 * Read-only field showing the collection of the current media item as a folder tile
 * (like the media overview) with its clickable path below. Tile and path segments
 * open the media overview of the respective collection.
 * Next to it, a non-clickable tile shows the storage path (e.g. `05/image.jpg`, from
 * the media's `storageOptions`) with file size and mime type (e.g. `12.34 MB | image/jpeg`).
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

    // Observed through the form store: updates when e.g. a new file version is uploaded
    get storage(): ?Storage {
        const {formInspector} = this.props;
        const storageOptions = formInspector.getValueByPath('/storageOptions');
        if (!storageOptions || typeof storageOptions !== 'object' || Array.isArray(storageOptions)) {
            return undefined;
        }

        const {fileName} = storageOptions;
        if (typeof fileName !== 'string' || fileName === '') {
            return undefined;
        }

        const path = STORAGE_PATH_KEYS
            .map((key) => storageOptions[key])
            .filter((part) => typeof part === 'string' && part !== '')
            .join('/');
        const size = formInspector.getValueByPath('/size');
        const mimeType = formInspector.getValueByPath('/mimeType');

        return {
            mimeType: typeof mimeType === 'string' && mimeType !== '' ? mimeType : undefined,
            path,
            size: typeof size === 'number' ? size : undefined,
        };
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

    renderCollectionTile(collection: Collection) {
        const info = `${collection.objectCount} ${
            translate(collection.objectCount === 1 ? 'sulu_admin.object' : 'sulu_admin.objects')
        }`;

        return (
            <FolderList onFolderClick={this.handleFolderClick}>
                <FolderList.Folder
                    hasPermissions={false}
                    id={collection.id}
                    info={info}
                    title={collection.title}
                />
            </FolderList>
        );
    }

    renderStorageTile(storage: Storage) {
        const info = [
            storage.size != null ? transformBytesToReadableString(storage.size) : undefined,
            storage.mimeType,
        ].filter(Boolean).join(' | ');

        return (
            <div style={storageTileStyle} title={storage.path}>
                <div style={storageIconStyle}>
                    <Icon name="su-document" />
                </div>
                <div style={storageDescriptionStyle}>
                    <h5 style={storageTitleStyle}>{storage.path}</h5>
                    {info && <div style={storageInfoStyle}>{info}</div>}
                </div>
            </div>
        );
    }

    render() {
        // Keep showing the previous collection while e.g. a locale switch reloads it
        if (this.loading && !this.collection) {
            return <Loader size={20} />;
        }

        const {collection, storage} = this;
        if (!collection && !storage) {
            return null;
        }

        return (
            <div>
                <div style={tilesStyle}>
                    {collection && this.renderCollectionTile(collection)}
                    {storage && this.renderStorageTile(storage)}
                </div>
                {collection &&
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
                }
            </div>
        );
    }
}

export default MediaCollectionPath;
